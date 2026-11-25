# ABATA AI — Railway deployment

Laravel 13 + Tailwind CSS + Python LightGBM + Newsvendor + MySQL. Based on the user's latest UMKM source archive. See [CARA_DEPLOY_RAILWAY.md](CARA_DEPLOY_RAILWAY.md) for complete Windows/GitHub/Railway setup and [DEPLOY_TEST_REPORT.md](DEPLOY_TEST_REPORT.md) for validation limits. Copy `RAILWAY_VARIABLES.example.txt` into the Railway web service Variables editor and replace placeholders (do not commit secrets).

The dataset/model remain synthetic/experimental. No training takes place during deployment. The initial DB mode is explicitly chosen: `seed` for a new empty database, `existing` for a complete imported database or an initialized database. Persistent data belongs to the MySQL volume, not the application container.
