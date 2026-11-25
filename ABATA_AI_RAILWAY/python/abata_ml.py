"""Canonical ABATA demo training and load-only inference. No database access."""
import argparse, hashlib, json, math, sys
from pathlib import Path
from statistics import NormalDist
import numpy as np
import pandas as pd
import lightgbm as lgb
import joblib, sklearn
SCOPE='synthetic_seed_v2'; STATUS='experimental_not_approved'; TARGETS=['quantity','demand_quantity']; CAL=60
FLAGS=['is_holiday','is_promotion','is_ramadan','is_eid_period']
F1=['day_of_week','day_of_month','month','is_weekend','is_payday']+[f'lag_{i}' for i in range(1,8)]+['rolling_mean_7','rolling_std_7']
F2=F1+[f'lag_{i}' for i in [14,21,28]]+[f'rolling_{s}_{w}' for w in [14,28] for s in ['mean','std']]+['trend_day']
SETS={'F1':F1,'F2':F2,'F3':F2+FLAGS}
CONFIGS=[dict(n_estimators=100,learning_rate=.05,num_leaves=7,min_child_samples=20,reg_lambda=1.),dict(n_estimators=160,learning_rate=.04,num_leaves=15,min_child_samples=30,reg_lambda=3.)]
def sha(p): return hashlib.sha256(Path(p).read_bytes()).hexdigest()
def save(p,x): Path(p).write_text(json.dumps(x,indent=2,ensure_ascii=False,allow_nan=False),encoding='utf-8')
def master_read(p):
    m=pd.read_csv(p).set_index('product_name').sort_index(); c=['purchase_cost','underage_cost','overage_cost','order_multiple','lead_time','shelf_life_days']
    if len(m)!=4 or not m.index.is_unique or not m.data_source.eq(SCOPE).all(): raise ValueError('Invalid demo master')
    m[c]=m[c].apply(pd.to_numeric,errors='raise')
    if not np.isfinite(m[c]).all().all() or (m[c]<=0).any().any() or not m.lead_time.eq(1).all(): raise ValueError('Invalid cost/multiple or unsupported lead time')
    m['cf']=m.underage_cost/(m.underage_cost+m.overage_cost); return m
def history_read(p,m):
    d=pd.read_csv(p); cols=['date','product_name','unit','data_source',*TARGETS,*FLAGS]
    if not set(cols)<=set(d) or d[cols].isna().any().any() or not d.data_source.eq(SCOPE).all(): raise ValueError('Missing fields or mixed scope')
    d.date=pd.to_datetime(d.date,format='%Y-%m-%d',errors='raise'); d=d.sort_values(['product_name','date']).reset_index(drop=True)
    if d.duplicated(['product_name','date']).any() or set(d.product_name)!=set(m.index): raise ValueError('Duplicate or unknown product')
    days=pd.date_range(d.date.min(),d.date.max())
    for p,g in d.groupby('product_name'):
        if not pd.DatetimeIndex(g.date).equals(days) or not g.unit.eq(m.loc[p,'unit']).all(): raise ValueError('Incomplete dates or unit mismatch: '+p)
    for c in TARGETS+FLAGS: d[c]=pd.to_numeric(d[c],errors='raise')
    if not np.isfinite(d[TARGETS+FLAGS]).all().all() or (d[TARGETS]<0).any().any() or (d.quantity>d.demand_quantity+1e-8).any() or not d[FLAGS].isin([0,1]).all().all() or (d.groupby('date')[FLAGS].nunique()>1).any().any(): raise ValueError('Invalid target/calendar')
    return d
def validate_seed(folder):
    p=Path(folder); m=master_read(p/'product_master.csv'); d=history_read(p/'dataset_abata_v2.csv',m); s=pd.read_csv(p/'menu_sales.csv'); b=pd.read_csv(p/'recipes_bom.csv'); c=pd.read_csv(p/'calendar.csv')
    if any(not x.data_source.eq(SCOPE).all() for x in [s,b,c]) or s.duplicated(['date','menu_id']).any() or b.duplicated(['menu_id','product_name']).any() or c.date.duplicated().any(): raise ValueError('Invalid seed keys/scope')
    if not (s.requested_qty==s.sold_qty+s.lost_qty).all() or not b.unit.eq(b.product_name.map(m.unit)).all(): raise ValueError('Invalid BOM/menu')
    j=s.merge(b,on='menu_id'); j.date=pd.to_datetime(j.date); j['u']=j.sold_qty*j.quantity_per_menu; j['d']=j.requested_qty*j.quantity_per_menu
    z=d.set_index(['date','product_name']).join(j.groupby(['date','product_name'])[['u','d']].sum(),how='outer'); diff=max((z.u-z.quantity).abs().max(),(z.d-z.demand_quantity).abs().max()); bal=(d.opening_stock+d.incoming_stock-d.quantity-d.waste_quantity-d.closing_stock).abs().max(); continuity=(d.opening_stock-d.groupby('product_name').closing_stock.shift()).abs().max()
    c.date=pd.to_datetime(c.date); zc=d.merge(c[['date']+FLAGS],on='date',suffixes=('','_cal'),validate='many_to_one')
    if z[['u','d','quantity','demand_quantity']].isna().any().any() or max(diff,bal,continuity)>.002 or len(zc)!=len(d) or any(not zc[f].eq(zc[f+'_cal']).all() for f in FLAGS): raise ValueError('Seed reconciliation failed')
    return dict(rows=len(d),days=int(d.date.nunique()),products=len(m),bom_rows=len(b),menu_rows=len(s),max_bom_error=float(diff),max_stock_error=float(bal),max_continuity_error=float(continuity),sha256={n:sha(p/n) for n in ['dataset_abata_v2.csv','product_master.csv','calendar.csv','menu_sales.csv','recipes_bom.csv']})
def features(g,t,origin):
    f=g.copy().sort_values('date'); dt=f.date.dt; f['day_of_week']=(dt.dayofweek+1)%7+1; f['day_of_month']=dt.day; f['month']=dt.month; f['is_weekend']=(dt.dayofweek>=5).astype(int); f['is_payday']=((dt.day>=25)|(dt.day<=3)).astype(int); f['trend_day']=(f.date-origin).dt.days
    for i in [1,2,3,4,5,6,7,14,21,28]: f[f'lag_{i}']=f[t].shift(i)
    for w in [7,14,28]:
        r=f[t].shift().rolling(w); f[f'rolling_mean_{w}']=r.mean(); f[f'rolling_std_{w}']=r.std(ddof=1)
    return f
def fit(f,cols,t,a,params): return lgb.LGBMRegressor(objective='quantile',alpha=a,random_state=42,n_jobs=1,verbosity=-1,deterministic=True,force_col_wise=True,**params).fit(f[cols],f[t])
def pin(y,p,a):
    e=np.asarray(y)-np.asarray(p); return float(np.maximum(a*e,(a-1)*e).mean())
def metrics(y,p,a):
    y=np.asarray(y,float); p=np.asarray(p,float); e=y-p; return dict(n=len(y),mae=float(abs(e).mean()),rmse=float(np.sqrt(np.mean(e**2))),wape=float(abs(e).sum()/abs(y).sum()) if abs(y).sum() else None,pinball=pin(y,p,a),coverage=float(np.mean(y<=p)))
def correction(r,a):
    x=np.sort(np.asarray(r)[-CAL:]); return float(x[min(len(x),max(1,math.ceil((len(x)+1)*a)))-1])
def monotone(x): return np.maximum.accumulate(np.maximum(0,x))
def qdefs(t,cf): return sorted([('q50',.5),('q80',.8)]+([('qcf',float(cf))] if t=='demand_quantity' else []),key=lambda z:z[1])
def prefix(d,cut):
    x=d.loc[d.date<=pd.Timestamp(cut),['date','product_name','unit','data_source',*TARGETS,*FLAGS]].copy(); x.date=x.date.dt.strftime('%Y-%m-%d'); x[TARGETS]=x[TARGETS].astype(float); x[FLAGS]=x[FLAGS].astype(int); return hashlib.sha256(x.to_csv(index=False,float_format='%.8f',lineterminator='\n').encode()).hexdigest()
def train(history,master,out,seed_folder=None):
    out=Path(out); (out/'models').mkdir(parents=True,exist_ok=True); m=master_read(master); d=history_read(history,m); days=pd.date_range(d.date.min(),d.date.max()); origin=days[0]; validation=validate_seed(seed_folder) if seed_folder else None
    if len(days)<300: raise ValueError('Requires >=300 complete days')
    ts=days[-30]; cs=days[-90]; ds=days[-60]; cv_ends=[cs-pd.Timedelta(days=1+30*k) for k in [2,1,0]]
    tune=[]; predictions=[]; residuals=[]; ev=[]; models=[]; run='abata-ml-'+hashlib.sha256((sha(history)+sha(master)+sha(__file__)).encode()).hexdigest()[:16]
    for product,g in d.groupby('product_name'):
        p=m.loc[product]
        for t in TARGETS:
            f=features(g,t,origin); dev=f[f.date<cs].iloc[28:]; ca=f[(f.date>=cs)&(f.date<ts)]; te=f[f.date>=ts]; qs=qdefs(t,p.cf); raw={}; corrected={}; sigma={}; baseline={}
            for q,a in qs:
                scores=[]
                for fs,cols in SETS.items():
                    for ci,params in enumerate(CONFIGS):
                        losses=[]
                        for end in cv_ends:
                            start=end-pd.Timedelta(days=29); tr=dev[dev.date<start]; va=dev[dev.date.between(start,end)]; model=fit(tr,cols,t,a,params); loss=pin(va[t],model.predict(va[cols]),a); losses.append(loss); tune.append(dict(run_id=run,product_name=product,target=t,quantile=q,alpha=a,feature_set=fs,config=ci,train_end=str(tr.date.max().date()),validation_start=str(start.date()),validation_end=str(end.date()),pinball=loss))
                        scores.append((np.mean(losses),fs,ci))
                score,fs,ci=min(scores); cols=SETS[fs]; params=CONFIGS[ci]; model=fit(dev,cols,t,a,params); cp=model.predict(ca[cols]); r=list(ca[t].to_numpy()-cp); raw[q]=model.predict(te[cols]); vals=[]; sig=[]
                for dt,y,rp in zip(ca.date,ca[t],cp): residuals.append(dict(run_id=run,product_name=product,target=t,quantile=q,alpha=a,date=str(dt.date()),actual=y,raw_prediction=rp,residual=y-rp,fit_end=str(dev.date.max().date())))
                for y,rp in zip(te[t],raw[q]): vals.append(rp+correction(r,a)); sig.append(float(np.std(r[-CAL:],ddof=1))); r.append(float(y-rp))
                corrected[q]=np.asarray(vals); sigma[q]=sig
                tr=f[f.date<ds].iloc[28:]; va=f[f.date>=ds]; model=fit(tr,cols,t,a,params); dr=va[t].to_numpy()-model.predict(va[cols]); name=f'{len(models):02d}_{t}_{q}'; txt=out/'models'/f'{name}.txt'; jb=out/'models'/f'{name}.joblib'; model.booster_.save_model(str(txt)); joblib.dump(model,jb,compress=3)
                models.append(dict(product_name=product,unit=p.unit,target=t,quantile=q,alpha=a,feature_set=fs,features=cols,parameters=params,cv_pinball=float(score),fit_end=str(tr.date.max().date()),calibration_start=str(ds.date()),calibration_end=str(days[-1].date()),initial_correction=correction(dr,a),initial_sigma=float(np.std(dr,ddof=1)),booster=str(txt.relative_to(out)),booster_sha256=sha(txt),joblib=str(jb.relative_to(out)),joblib_sha256=sha(jb)))
                baseline[q]=f[t].shift().rolling(28).quantile(a,interpolation='linear').iloc[-30:].to_numpy()
            final=np.array([monotone(x) for x in np.column_stack([corrected[q] for q,a in qs])]); y=te[t].to_numpy()
            for k,(q,a) in enumerate(qs):
                for i,dt in enumerate(te.date): predictions.append(dict(run_id=run,product_name=product,unit=p.unit,target=t,date=str(dt.date()),quantile=q,alpha=a,actual=float(y[i]),raw_prediction=float(raw[q][i]),calibrated_prediction=float(corrected[q][i]),prediction=float(final[i,k]),sigma_q50=sigma['q50'][i],empirical_prediction=float(baseline[q][i])))
                comps={'raw':raw[q],'calibrated':corrected[q],'final':final[:,k],'empirical28':baseline[q]}
                if q=='q50': comps.update(lag1=te.lag_1,lag7=te.lag_7,median7=f[t].shift().rolling(7).median().iloc[-30:])
                for v,pr in comps.items(): ev.append(dict(run_id=run,product_name=product,unit=p.unit,target=t,quantile=q,alpha=a,variant=v,**metrics(y,pr,a)))
            print('Trained',product,t,file=sys.stderr,flush=True)
    pred=pd.DataFrame(predictions); assert len(pred)==600 and not pred.duplicated(['product_name','target','date','quantile']).any()
    for n,x in [('test_predictions',pred),('evaluation',pd.DataFrame(ev)),('tuning',pd.DataFrame(tune)),('calibration_residuals',pd.DataFrame(residuals))]: x.to_csv(out/f'{n}.csv',index=False)
    man=dict(schema_version='abata-ml-3',run_id=run,status=STATUS,scope=SCOPE,history_sha256=sha(history),master_sha256=sha(master),pipeline_sha256=sha(__file__),history_start=str(origin.date()),history_end=str(days[-1].date()),deployment_fit_end=str(days[-61].date()),fit_prefix_sha256=prefix(d,days[-61]),historical_test_start=str(ts.date()),historical_test_end=str(days[-1].date()),evaluation_label='exploratory_historical_rolling_origin_not_independent',calibration='Rolling previous 60 raw residuals only; finite sample order statistic; cumulative max in alpha order',python=sys.version.split()[0],dependencies={'lightgbm':lgb.__version__,'pandas':pd.__version__,'numpy':np.__version__,'scikit-learn':sklearn.__version__,'joblib':joblib.__version__},seed_validation=validation,models=models); save(out/'manifest.json',man); return man
def predict(history,master,model_dir,forecast_date,calendar=None):
    md=Path(model_dir); man=json.loads((md/'manifest.json').read_text()); m=master_read(master); d=history_read(history,m); date=pd.Timestamp(forecast_date)
    if man['scope']!=SCOPE or man['status']!=STATUS or man['schema_version']!='abata-ml-3' or sha(master)!=man['master_sha256']: raise ValueError('Invalid manifest/master')
    if date!=d.date.max()+pd.Timedelta(days=1) or d.date.max()<pd.Timestamp(man['history_end']) or prefix(d,man['deployment_fit_end'])!=man['fit_prefix_sha256']: raise ValueError('Need complete H+1 history with unchanged fit prefix')
    flags={c:np.nan for c in FLAGS}
    if calendar:
        z=pd.read_csv(calendar)
        if len(z)!=1 or str(z.date.iloc[0])!=str(date.date()) or not z[FLAGS].isin([0,1]).all().all() or z.data_source.iloc[0]!=SCOPE: raise ValueError('Invalid target calendar scenario')
        flags={c:int(z[c].iloc[0]) for c in FLAGS}
    rows=[]
    for product,g in d.groupby('product_name'):
        for t in TARGETS:
            rr=sorted([x for x in man['models'] if x['product_name']==product and x['target']==t],key=lambda x:x['alpha']); vals=[]; details=[]; sigma=None
            f=features(pd.concat([g,pd.DataFrame([dict(date=date,**flags)])],ignore_index=True),t,pd.Timestamp(man['history_start']))
            for v in rr:
                path=(md/v['booster']).resolve(); cols=v['features']
                if not path.is_relative_to(md.resolve()) or sha(path)!=v['booster_sha256']: raise ValueError('Artifact checksum/path mismatch')
                model=lgb.Booster(model_file=str(path)); past=f[(f.date>pd.Timestamp(v['fit_end']))&(f.date<date)].tail(CAL)
                if len(past)!=CAL or f[cols].iloc[-1].isna().any(): raise ValueError('Missing target calendar or residual history')
                r=past[t].to_numpy()-model.predict(past[cols],num_threads=1); raw=float(model.predict(f[cols].iloc[[-1]],num_threads=1)[0]); c=correction(r,v['alpha']); vals.append(raw+c)
                if v['quantile']=='q50': sigma=float(np.std(r,ddof=1))
                details.append(dict(quantile=v['quantile'],alpha=v['alpha'],raw=raw,correction=c,feature_set=v['feature_set']))
            if len(rr)!=(2 if t=='quantity' else 3): raise ValueError('Incomplete estimator set')
            rows.append(dict(product_name=product,unit=m.loc[product,'unit'],target=t,**{v['quantile']:float(x) for v,x in zip(rr,monotone(vals))},sigma_q50=sigma,critical_fractile=float(m.loc[product,'cf']),empirical_qcf=float(g[t].tail(28).quantile(m.loc[product,'cf'])),details=details))
    return dict(schema_version='abata-ml-3',run_id=man['run_id'],status=STATUS,scope=SCOPE,forecast_date=str(date.date()),as_of_date=str(d.date.max().date()),history_sha256=sha(history),calendar_source='explicit_demo_scenario' if calendar else 'not_supplied',predictions=rows)
def recommend(fc,master,stocks):
    m=master_read(master); s=pd.read_csv(stocks).set_index('product_name'); rows=[]
    if not s.index.is_unique or set(s.index)!=set(m.index) or not s.data_source.eq(SCOPE).all() or not s.as_of_date.eq(fc['as_of_date']).all() or fc['scope']!=SCOPE: raise ValueError('Invalid stock snapshot')
    for r in fc['predictions']:
        if r['target']!='demand_quantity': continue
        p=r['product_name']; v=m.loc[p]; x=s.loc[p]; rice=p=='Nasi Porsi'; n=np.array([x.usable_on_hand,x.eligible_inbound,x.reserved],float)
        if not np.isfinite(n).all() or (n<0).any() or x.unit!=v.unit or (rice and n.any()): raise ValueError('Invalid stock or rice carryover')
        pos=float(n[0]+n[1]-n[2]); ss=NormalDist().inv_cdf(.8)*r['sigma_q50']
        for pol,level in [('fixed_q80',r['q80']),('conservative',r['q80']+ss),('cost_based',r['qcf']),('empirical28',r['empirical_qcf'])]:
            raw=max(0,level-pos); qty=math.ceil(max(0,raw/v.order_multiple-1e-10))*float(v.order_multiple)
            rows.append(dict(product_name=p,unit=v.unit,policy=pol,action='production_plan' if rice else 'purchase_order_draft',target_stock=level,inventory_position=pos,safety_stock=ss if pol=='conservative' else 0.,raw_order=raw,recommended_qty=qty,planned_expenditure_idr=qty*float(v.purchase_cost),historical_fill_rate=None))
    return {k:fc[k] for k in ['schema_version','run_id','status','scope','forecast_date','as_of_date']}|{'recommendations':rows,'warning':'Demo; conservative is heuristic. No automatic order. Caller supplies usable stock and confirmed inbound.'}
def main():
    ap=argparse.ArgumentParser(); sp=ap.add_subparsers(dest='cmd',required=True)
    for command,fields,optional in [('train',['history','master','out'],['seed-folder']),('predict',['history','master','model-dir','forecast-date'],['calendar','out']),('recommend',['forecast','master','stocks'],['out'])]:
        a=sp.add_parser(command)
        for f in fields: a.add_argument('--'+f,required=True)
        for f in optional: a.add_argument('--'+f)
    a=ap.parse_args()
    try:
        if a.cmd=='train': r=train(a.history,a.master,a.out,a.seed_folder); r={k:r[k] for k in ['run_id','status','schema_version']}
        elif a.cmd=='predict': r=predict(a.history,a.master,a.model_dir,a.forecast_date,a.calendar)
        else: r=recommend(json.loads(Path(a.forecast).read_text()),a.master,a.stocks)
        if a.cmd in ['predict','recommend'] and a.out: save(a.out,r)
        print(json.dumps(r,ensure_ascii=False,allow_nan=False))
    except Exception as e: print(json.dumps({'status':'error','error':str(e)}),file=sys.stderr); sys.exit(1)
if __name__=='__main__': main()
