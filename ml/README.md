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

#### On Windows:
```bash
cd c:\xampp\htdocs\Capstone1\ml
run_server.bat
```

#### On Linux/Mac:
```bash
cd /path/to/Capstone1/ml
python3 run_server.py
```

Or manually install dependencies:
```bash
pip install -r requirements.txt
```

### Step 2: Configure Database

1. **Import ML Tables** into your `espenida_pos` database:
   - Open phpMyAdmin or MySQL Workbench
   - Navigate to your `espenida_pos` database
   - Import the SQL file: `ml/ml_tables_migration.sql`
   - Verify new tables are created (see Database Schema section below)

2. **Verify Connection**:
   ```php
   // The system will automatically attempt to connect
   // If connection fails, check:
   // - ml/ml_config.py - DB_CONFIG must match your database credentials
   // - MySQL service is running
   // - Database exists and is accessible
   ```

### Step 3: Start the ML API Server

#### Quick Start (Recommended):

**Windows:**
```bash
cd c:\xampp\htdocs\Capstone1\ml
run_server.bat
```

**Linux/Mac:**
```bash
cd /path/to/Capstone1/ml  
python3 run_server.py
```

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
Task: C:\xampp\htdocs\Capstone1\ml\run_server.bat
```

## 📈 Understanding the ML Algorithm

### Random Forest Regressor:

- **Algorithm**: Ensemble of decision trees
- **Advantages**: 
  - Handles non-linear relationships
  - Robust to outliers
  - Feature importance rankings
  - No scaling required (usually)

- **Input Features**:
  1. Current stock level
  2. Unit price
  3. Cost price
  4. Markup percentage
  5. Historical sales volume
  6. Sales trend (improving/declining)
  7. Days until expiry
  8. Inventory turnover rate
  9. Revenue to cost ratio

- **Output**: Predicted monthly sales velocity

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
│   ├── data_processor.py    # Data preprocessing
│   ├── db_connector.py      # Database interface
│   ├── ml_config.py        # Configuration
│   ├── requirements.txt     # Python dependencies
│   ├── run_server.bat       # Windows launcher
│   ├── run_server.py        # Cross-platform launcher
│   ├── __init__.py          # Package init
│   ├── ml_tables_migration.sql  # Database schema
│   ├── models/              # Trained model storage
│   ├── logs/                # Application logs
│   └── README.md
│
├── ajax/
│   ├── ml_recommendation_ajax.php  # API bridge (NEW)
│   └── ...existing files...
│
├── reco.php                 # Recommendation dashboard (UPDATED)
├── espenida_pos.sql         # Database with ML tables (UPDATED)
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
