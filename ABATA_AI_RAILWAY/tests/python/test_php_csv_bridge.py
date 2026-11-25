"""Actual PHP CSV serializer -> Python model smoke test; no Laravel dependency."""
import hashlib,json,subprocess,sys,tempfile,unittest,shutil
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'python'))
import abata_ml as ml
class BridgeTest(unittest.TestCase):
    def test_artifact_checksums(self):
        m=json.loads((ROOT/'ml_models/manifest.json').read_text()); self.assertEqual(len(m['models']),20)
        self.assertEqual(ml.sha(ROOT/'python/abata_ml.py'),m['pipeline_sha256'])
        for x in m['models']:
            for k in ('booster','joblib'): self.assertEqual(ml.sha(ROOT/'ml_models'/x[k]),x[k+'_sha256'])
        for f,h in m['seed_validation']['sha256'].items(): self.assertEqual(ml.sha(ROOT/'datasets'/f),h)
    @unittest.skipUnless(shutil.which('php'),'PHP unavailable')
    def test_php_csv_model_roundtrip(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/'history.csv'; php='require "app/Services/Csv.php"; $r=App\\Services\\Csv::read("datasets/dataset_abata_v2.csv"); App\\Services\\Csv::write($argv[1],["date","product_name","unit","data_source","quantity","demand_quantity","is_holiday","is_promotion","is_ramadan","is_eid_period"],$r);'
            subprocess.run(['php','-r',php,str(path)],cwd=ROOT,check=True,capture_output=True)
            fc=ml.predict(path,ROOT/'datasets/product_master.csv',ROOT/'ml_models','2026-11-25',ROOT/'ml_models/calendar_demo.csv')
            self.assertEqual(fc['predictions'],json.loads((ROOT/'ml_models/inference.json').read_text())['predictions'])
            rec=ml.recommend(fc,ROOT/'datasets/product_master.csv',ROOT/'ml_models/stock_demo.csv')
            self.assertEqual(rec['recommendations'],json.loads((ROOT/'ml_models/recommendations.json').read_text())['recommendations'])
if __name__=='__main__': unittest.main()
