# ML Recommendation System - Complete Setup Guide

## 📋 System Requirements

### Python Requirements
- **Python**: 3.8 or higher
- **Operating System**: Windows, Linux, or macOS
- **RAM**: Minimum 4GB (8GB recommended for large datasets)
- **Disk Space**: 500MB minimum

### PHP & Database Requirements
- **PHP**: 7.4 or higher (with cURL enabled)
- **MySQL/MariaDB**: 5.7 or higher
- **Apache/Web Server**: With mod_rewrite enabled

## 🚀 Installation Steps

### Step 1: Install Python Dependencies

Run once, from the `ml` folder:
```bash
pip install -r requirements.txt
```

### Step 2: Configure Database

1. **Import the database** (the ML tables are included):
   - Open phpMyAdmin or MySQL Workbench
   - Import `Database SQL/espenida_pos.sql` — it creates the `espenida_pos` database itself
   - Verify the ML tables exist (see Database Schema section below)

2. **Verify Connection**:
   ```php
   // The system will automatically attempt to connect
   // If connection fails, check:
   // - ml/ml_config.py - DB_CONFIG must match your database credentials
   // - MySQL service is running
   // - Database exists and is accessible
   ```

### Step 3: Start the ML API Server

#### Automatic (default):

Nothing to run. The first request that needs the ML server (for example opening
Recommendations) starts `api_server.py` in the background — see the auto-start
code at the top of `ajax/ml_recommendation_ajax.php`. It finds the Python that
has the packages above (or the one in the `ML_PYTHON` environment variable) and
writes its output to `ml/logs/ml_server.log`. To stop it, run `stop_server.vbs`.

#### Manual Start:

**Windows (PowerShell):**
```powershell
cd c:\xampp\htdocs\Capstone1\ml
pip install -r requirements.txt
python api_server.py
```

**Linux/Mac (Terminal):**
```bash
cd /path/to/Capstone1/ml
pip3 install -r requirements.txt
python3 api_server.py
```

You should see output like:
```
========================================
ML Recommendation API Server Startup
========================================

Starting ML API Server on http://127.0.0.1:5000
========================================
Press Ctrl+C to stop the server
```

### Step 4: Verify Server is Running

Open your browser and visit:
```
http://127.0.0.1:5000/health
```

You should see JSON response:
```json
{
  "status": "ok",
  "model_loaded": true,
  "timestamp": "2026-03-31T10:30:45.123456"
}
```

### Step 5: Access the Recommendations Dashboard

1. Log in to your POS system
2. Navigate to: **Smart Recommendations** (or `/reco.php`)
3. You should see:
   - Service status indicator (green = connected)
   - Statistics cards
   - Product recommendation cards
   - Forecast & Strategy application features

## 📊 Database Schema

### New ML Tables Created:

1. **ml_recommendation_logs**
   - Tracks all generated and applied recommendations
   - Records actual outcomes for learning

2. **ml_model_metrics**
   - Stores model performance metrics (MSE, RMSE, MAE, R²)
   - Historical tracking of model accuracy

3. **ml_feature_importance**
   - Ranks which product features most influence predictions
   - Helps understand ML model decision-making

4. **ml_product_profiles**
   - Pre-calculated features for each product
   - Enables faster inference and debugging

5. **ml_training_history**
   - Logs every model training run
   - Useful for troubleshooting and optimization

6. **ml_prediction_accuracy**
   - Tracks prediction accuracy over time
   - Feed for continuous learning

## 🔧 Configuration

### Update ML Settings (ml/ml_config.py):

```python
DB_CONFIG = {
    'host': 'localhost',        # Your MySQL host
    'user': 'root',             # MySQL username
    'password': '',             # MySQL password
    'database': 'espenida_pos', # Database name
    'port': 3306
}

ML_CONFIG = {
    'model_type': 'RandomForestRegressor',
    'n_estimators': 100,    # Number of trees (increase = more accuracy, slower)
    'max_depth': 15,        # Tree depth (prevent overfitting)
    'random_state': 42      # For reproducibility
}
```

### API Configuration:

```python
API_CONFIG = {
    'debug': True,           # Set to False in production
    'host': '127.0.0.1',     # API server address
    'port': 5000,            # API server port
    'threaded': True         # Enable multi-threading
}
```

## ⚙️ ML Model Retraining

### Manual Retraining:

1. **Via Dashboard:**
   - Log in as Owner
   - Click "🔄 Train Model" button in Smart Recommendations page

2. **Via Command Line:**
```bash
cd ml
python3 -c "from recommendation_model import RecommendationModel; m = RecommendationModel(); m.train_model()"
```

### Automatic Retraining Schedule:

Add to your server's cron job or Windows Task Scheduler:

**Linux/Mac (crontab):**
```cron
# Retrain model daily at 2 AM
0 2 * * * cd /path/to/Capstone1/ml && python3 api_server.py >> /tmp/ml_auto_train.log 2>&1
```

**Windows (Task Scheduler):**
```
Trigger: Daily at 2:00 AM
Task: python C:\xampp\htdocs\Capstone\ml\api_server.py
```

## 📈 Understanding the ML Algorithm

### Random Forest Regressor:

- **Algorithm**: Ensemble of decision trees
- **Advantages**: 
  - Handles non-linear relationships
  - Robust to outliers
  - Feature importance rankings
  - No scaling required (usually)

- **Input Features** (what was known on the day of the forecast; see `FORECAST_FEATURE_NAMES` in `ml_config.py`):
  1. Units sold in the last 7 days
  2. Units sold in the last 30 days
  3. Units sold in the 30 days before that
  4. Days with at least one sale, last 30 days
  5. Days with at least one sale, last 60 days
  6. Days since the last sale
  7. Sales trend (last 30 days against the 30 before)
  8. How uneven daily sales are
  9. Unit price
  10. Markup percentage
  11. Product age (up to 60 days)
  12. Category

- **Output**: Units the product is expected to sell in the next 30 days

- **Training**: each example is a product on a past day, with its sales in the 60 days
  before that day as inputs and the units it sold in the 30 days after as the answer.
  The answer always lies after the inputs, so the model is never given what it has to
  predict. Stretches where the store recorded no sales for more than 3 days are skipped.

- **Score**: the most recent month of history is held out and predicted by a model
  trained only on earlier data. It is reported next to a no-model baseline ("next 30
  days = previous 30 days") in `ml_model_metrics` and `/api/model-info`.
  About four months of unbroken sales history are needed before the model can train;
  until then, and for products with no sale in the last 60 days, past sales are used.

## 🎯 Recommendation Strategy Levels

### 1. Emergency Clearance (Critical)
- **Trigger**: Items expiring in <7 days + low sales
- **Discount**: 25-40% OFF
- **Duration**: 3 days
- **Expected Impact**: 70-105% sales uplift

### 2. Bundle & Save (High Priority)
- **Trigger**: Slow-moving items
- **Discount**: 10-20% on bundle
- **Duration**: 7 days
- **Expected Impact**: 25-40% units moved increase

### 3. Loyalty Push (Medium Priority)
- **Trigger**: Moderate stock with repeat customers
- **Discount**: 5-15%
- **Duration**: 14 days
- **Expected Impact**: 15-30% uplift

### 4. Targeted Promotion (Low Priority)
- **Trigger**: Seasonal or trending products
- **Discount**: 0-10%
- **Duration**: 7 days
- **Expected Impact**: 10-25% uplift

## 🔍 Troubleshooting

### Issue: "API Connection Error"

**Solution:**
1. Verify Python server is running
2. Check firewall allows port 5000
3. Verify correct IP/port in `ajax/ml_recommendation_ajax.php`

```bash
# Test API connectivity
curl http://127.0.0.1:5000/health
```

### Issue: "Model not trained"

**Solution:**
- Need minimum 5 transactions for each product
- Run manual training: Click "Train Model" button
- Check database has transaction data

### Issue: "Database connection failed"

**Solution:**
1. Check MySQL credentials in `ml/ml_config.py`:
```python
# Verify these match your actual database
DB_CONFIG = {
    'host': 'localhost',
    'user': 'root',
    'password': '',     # Your actual password
    'database': 'espenida_pos'
}
```

2. Test connection manually:
```python
python3 -c "from db_connector import DatabaseConnector; DatabaseConnector.get_connection()"
```

### Issue: Low prediction accuracy

**Solutions:**
1. Increase training data (more transactions)
2. Adjust hyperparameters in `ml_config.py`
3. Regular model retraining

## 📝 File Structure

```
Capstone1/
├── ml/                      # ML Engine (NEW)
│   ├── api_server.py       # Flask API server
│   ├── recommendation_model.py  # ML model & logic
│   ├── data_service.py      # Data preprocessing & database access
│   ├── ml_config.py        # Configuration
│   ├── requirements.txt     # Python dependencies
│   ├── stop_server.vbs      # Stops the background server
│   ├── __init__.py          # Package init
│   ├── models/              # Trained model storage
│   ├── logs/                # Server log (auto-start)
│   └── README.md
│
├── ajax/
│   ├── ml_recommendation_ajax.php  # API bridge (NEW)
│   └── ...existing files...
│
├── reco.php                 # Recommendation dashboard (UPDATED)
├── Database SQL/
│   └── espenida_pos.sql     # The whole database, ML tables included
└── ...
```

## 🚪 API Endpoints

### `GET /health`
Check API server status

Response:
```json
{"status": "ok", "model_loaded": true}
```

### `GET /api/recommendations?limit=10`
Get all product recommendations

### `GET /api/recommendations?product_id=5`
Get recommendation for specific product

### `POST /api/forecast`
Get sales forecast for a product

Body:
```json
{"product_id": 5, "days_ahead": 30}
```

### `POST /api/train-model`
Retrain ML model with latest data

### `POST /api/save-recommendation`
Save applied recommendation and outcomes

Body:
```json
{
  "product_id": 5,
  "strategy_id": "emergency_clearance",
  "discount_percentage": 30,
  "notes": "Customer feedback positive"
}
```

## 📊 Dashboard Features

### Overview Statistics
- **Slow-Moving Items**: Number of products with low sales
- **Potential Revenue**: Revenue opportunity from recommendations
- **Avg. Shelf Time**: Average days in stock
- **Prediction Confidence**: Average ML model confidence

### Product Cards
Each product shows:
- Risk level (Critical/High/Moderate/Low)
- ML confidence score
- Expiry countdown
- Sales metrics
- Risk score
- Top recommended strategy
- Action buttons (Forecast, Apply)

### Forecasting
- 30-day sales prediction
- Daily breakdown chart
- Confidence percentage

### Strategy Application
- View all ranked strategies
- Add implementation notes
- Track recommended discounts
- Monitor expected impact

## 📞 Support & Maintenance

### Regular Maintenance:
1. **Weekly**: Check server logs for errors
2. **Monthly**: Review model accuracy metrics
3. **Quarterly**: Retrain model with accumulated data
4. **Annually**: Audit and optimize performance

### Logs Location:
- API: `ml/logs/ml.log`
- Database: MySQL error log
- Web: Apache/nginx access logs

### Performance Tuning:
- Increase `n_estimators` in ML_CONFIG for better accuracy
- Adjust `max_depth` to prevent overfitting
- Monitor database query performance

## ✅ Verification Checklist

- [ ] Python 3.8+ installed
- [ ] ML requirements installed (`pip install -r requirements.txt`)
- [ ] MySQL/MariaDB running
- [ ] ML tables imported into database
- [ ] ML API server running (`python api_server.py`)
- [ ] API health check returns success
- [ ] reco.php loads without errors
- [ ] At least 5 transactions per product in database
- [ ] Dashboard shows recommendations
- [ ] Forecast and Apply buttons work

## 🎓 Learning More

### ML Concepts:
- https://scikit-learn.org/stable/modules/ensemble.html#forests
- Random Forest explanation: https://towardsdatascience.com/understanding-random-forest-58381e0602d2

### Python for POS:
- Flask Documentation: https://flask.palletsprojects.com/
- Pandas Documentation: https://pandas.pydata.org/docs/

---

**System Created**: March 31, 2026
**ML Engine Version**: 1.0.0
**Status**: Production Ready

For issues or improvements, check the logs and verify all components are correctly configured.
