import json, os, shutil, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
import numpy as np, pandas as pd
import sys
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'python'))
import abata_ml as ml
SEED=Path(os.environ.get('ABATA_SEED_DIR',ROOT/'datasets'))
H=SEED/'dataset_abata_v2.csv'; M=SEED/'product_master.csv'; R=ROOT/'ml_models'; C=R/'calendar_demo.csv'
class TestAbata(unittest.TestCase):
    def test_seed(self):
        self.assertEqual(ml.validate_seed(SEED)['rows'],2920)
    def test_causal(self):
        d=ml.history_read(H,ml.master_read(M)); g=next(iter(d.groupby('product_name')))[1].copy(); start=g.date.min(); i=g.index[50]; a=ml.features(g,'quantity',start); g.loc[i:,'quantity']+=100; b=ml.features(g,'quantity',start); np.testing.assert_allclose(a.loc[i,ml.F2].astype(float),b.loc[i,ml.F2].astype(float)); self.assertNotEqual(a.loc[i+1,'lag_1'],b.loc[i+1,'lag_1'])
    def test_missing_duplicate_scope(self):
        d=pd.read_csv(H); m=ml.master_read(M)
        with tempfile.TemporaryDirectory() as td:
            path=Path(td)/'history.csv'; mixed=d.copy(); mixed.loc[0,'data_source']='operational'
            for x in [d.iloc[1:],pd.concat([d,d.iloc[:1]]),mixed]:
                x.to_csv(path,index=False)
                with self.assertRaises(ValueError): ml.history_read(path,m)
    def test_prediction_evaluation(self):
        p=pd.read_csv(R/'test_predictions.csv'); e=pd.read_csv(R/'evaluation.csv'); self.assertEqual(len(p),600); self.assertFalse(p.duplicated(['target','product_name','date','quantile']).any()); self.assertEqual(len(p.query("target=='demand_quantity'")),360)
        for keys,g in p.groupby(['target','product_name','quantile']):
            v=e[(e.target==keys[0])&(e.product_name==keys[1])&(e['quantile']==keys[2])&(e.variant=='final')].iloc[0]; met=ml.metrics(g.actual,g.prediction,g.alpha.iloc[0]); self.assertTrue(all(np.isclose(v[k],value) for k,value in met.items()))
        for _,g in p.groupby(['target','product_name','date']): self.assertTrue((np.diff(g.sort_values('alpha').prediction)>=0).all())
    def test_residual_dates(self):
        r=pd.read_csv(R/'calibration_residuals.csv'); t=pd.read_csv(R/'tuning.csv'); self.assertTrue((r.date>r.fit_end).all()); self.assertTrue((r.date<'2026-10-26').all()); self.assertTrue((t.train_end<t.validation_start).all()); self.assertTrue((t.validation_end<r.date.min()).all()); self.assertTrue(np.allclose(r.actual-r.raw_prediction,r.residual))
    def test_load_only_and_future(self):
        with patch.object(ml.lgb.LGBMRegressor,'fit',side_effect=AssertionError('Unexpected training')):
            f=ml.predict(H,M,R,'2026-11-25',C); self.assertEqual(len(f['predictions']),8)
            with self.assertRaises(ValueError): ml.predict(H,M,R,'2026-11-26',C)
            with tempfile.TemporaryDirectory() as td:
                td=Path(td); d=pd.read_csv(H); x=d[d.date==d.date.max()].copy(); x.date='2026-11-25'; x['quantity']*=.9; x['demand_quantity']*=.9; pd.concat([d,x]).to_csv(td/'h.csv',index=False); c=pd.read_csv(C); c.date='2026-11-26'; c.to_csv(td/'c.csv',index=False); f2=ml.predict(td/'h.csv',M,R,'2026-11-26',td/'c.csv'); self.assertEqual(f2['forecast_date'],'2026-11-26'); self.assertTrue(any(a['q50']!=b['q50'] for a,b in zip(f['predictions'],f2['predictions'])))
    def test_tamper(self):
        with tempfile.TemporaryDirectory() as td:
            path=Path(td)/'run'; shutil.copytree(R,path); m=json.loads((path/'manifest.json').read_text()); p=path/m['models'][0]['booster']; p.write_bytes(p.read_bytes()+b'changed');
            with self.assertRaisesRegex(ValueError,'checksum'): ml.predict(H,M,path,'2026-11-25',C)
    def test_restock(self):
        f=json.loads((R/'inference.json').read_text()); r=ml.recommend(f,M,R/'stock_demo.csv'); self.assertEqual(len(r['recommendations']),16); self.assertTrue(all(x['recommended_qty']>=0 for x in r['recommendations'])); self.assertTrue(all(x['action']=='production_plan' for x in r['recommendations'] if x['product_name']=='Nasi Porsi'))
        with tempfile.TemporaryDirectory() as td:
            s=pd.read_csv(R/'stock_demo.csv'); s.loc[s.product_name!='Nasi Porsi','usable_on_hand']=100000; path=Path(td)/'s.csv'; s.to_csv(path,index=False); a=ml.recommend(f,M,path); self.assertTrue(all(x['recommended_qty']==0 for x in a['recommendations'] if x['product_name']!='Nasi Porsi'))
    def test_backtest(self):
        x=pd.read_csv(R/'sequential_backtest.csv'); self.assertEqual(len(x),480); self.assertFalse(x.duplicated(['product_name','date','policy']).any()); self.assertTrue(np.allclose(x.opening+x.receipt-x.fulfilled-x.waste,x.closing)); self.assertTrue(x[x.product_name=='Nasi Porsi'].closing.eq(0).all()); self.assertTrue(np.allclose(x.fulfilled+x.shortage,x.demand))
    def test_cross_platform_lineterminator(self):
        import hashlib; d=ml.history_read(H,ml.master_read(M)); cut='2026-09-25'; hash1=ml.prefix(d,cut); m=json.loads((R/'manifest.json').read_text()); self.assertEqual(hash1,m['fit_prefix_sha256'],'Windows fix: prefix hash matches manifest')
    def test_prefix_hash_deterministic(self):
        d=ml.history_read(H,ml.master_read(M)); cut='2026-09-25'; hash_runs=[ml.prefix(d,cut) for _ in range(3)]; self.assertTrue(all(h==hash_runs[0] for h in hash_runs),'Prefix hash deterministic across runs')
if __name__=='__main__': unittest.main(verbosity=2)
