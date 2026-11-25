"""Exploratory H+1 inventory simulation, using canonical historical predictions."""
from pathlib import Path
from statistics import NormalDist
import math
import pandas as pd
from abata_ml import master_read, history_read, save

def backtest(history,master,predictions,out):
    m=master_read(master); d=history_read(history,m); p=pd.read_csv(predictions); p=p[p.target.eq('demand_quantity')]; rows=[]
    if len(p)!=360 or p.duplicated(['product_name','date','quantile']).any(): raise ValueError('Invalid predictions')
    for product,g in p.groupby('product_name'):
        v=m.loc[product]; h=d[d.product_name.eq(product)].set_index('date'); w=g.pivot(index='date',columns='quantile',values='prediction'); q=g[g['quantile'].eq('q50')].set_index('date'); cf=g[g['quantile'].eq('qcf')].set_index('date'); initial=float(h.loc[pd.Timestamp(w.index[0])-pd.Timedelta(days=1),'closing_stock']); rice=product=='Nasi Porsi'
        for policy in ['fixed_q80','conservative','cost_based','empirical28']:
            lots=[[0,initial]] if initial>0 and not rice else []
            for i,(dt,r) in enumerate(w.iterrows()):
                opening=sum(x[1] for x in lots); actual=float(q.loc[dt,'actual']); level={'fixed_q80':r.q80,'conservative':r.q80+NormalDist().inv_cdf(.8)*float(q.loc[dt,'sigma_q50']),'cost_based':r.qcf,'empirical28':float(cf.loc[dt,'empirical_prediction'])}[policy]; order=math.ceil(max(0,max(0,level-opening)/v.order_multiple-1e-10))*float(v.order_multiple)
                if order: lots.append([i,order])
                remaining=actual
                for x in lots:
                    used=min(x[1],remaining); x[1]-=used; remaining-=used
                waste=sum(x[1] for x in lots if i-x[0]+1>=v.shelf_life_days); lots=[x for x in lots if i-x[0]+1<v.shelf_life_days and x[1]>1e-9]; closing=sum(x[1] for x in lots); fulfilled=actual-remaining
                assert abs(opening+order-fulfilled-waste-closing)<1e-6
                rows.append(dict(run_id=q.loc[dt,'run_id'],product_name=product,unit=v.unit,date=dt,policy=policy,opening=opening,order_previous_day=order,receipt=order,demand=actual,fulfilled=fulfilled,shortage=remaining,waste=waste,closing=closing,purchase_expenditure=order*v.purchase_cost,shortage_cost=remaining*v.underage_cost,waste_cost=waste*v.overage_cost,cost_proxy=remaining*v.underage_cost+waste*v.overage_cost,stockout=int(remaining>1e-8)))
    z=pd.DataFrame(rows); assert len(z)==480 and not z.duplicated(['product_name','date','policy']).any(); summary=[]
    for (product,pol),g in z.groupby(['product_name','policy']): summary.append(dict(product_name=product,unit=g.unit.iloc[0],policy=pol,days=len(g),fill_rate=float(g.fulfilled.sum()/g.demand.sum()) if g.demand.sum() else None,cycle_service=float(1-g.stockout.mean()),stockout_rate=float(g.stockout.mean()),total_order=float(g.receipt.sum()),shortage=float(g.shortage.sum()),waste=float(g.waste.sum()),terminal_stock=float(g.closing.iloc[-1]),purchase_expenditure=float(g.purchase_expenditure.sum()),cost_proxy=float(g.cost_proxy.sum())))
    out=Path(out); z.to_csv(out/'sequential_backtest.csv',index=False); pd.DataFrame(summary).to_csv(out/'backtest_summary.csv',index=False); result=dict(status='exploratory_demo',rows=len(z),assumptions='Perfect H+1 receipts; initial lots assumed fresh; FEFO and expiry; no holding/terminal valuation, capacity or joint-menu fulfillment. Proxy shortage+waste only, expenditure separate. Forecasts use observed historical demand, not simulated fulfilled usage.',summary=summary); save(out/'backtest_summary.json',result); return result
if __name__=='__main__':
    import argparse
    p=argparse.ArgumentParser(); p.add_argument('--history',required=True); p.add_argument('--master',required=True); p.add_argument('--predictions',required=True); p.add_argument('--out',required=True); a=p.parse_args(); backtest(a.history,a.master,a.predictions,a.out)
