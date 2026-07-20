"""
ML Recommendation Engine Package.

Provides machine learning-based product recommendations using Random Forest
models with sales forecasting and risk prediction capabilities.

Version: 1.0.0
Author: POS Intelligence Team
"""

from .recommendation_model import RecommendationModel
from .data_service import DataProcessor, DatabaseConnector

__version__ = "1.0.0"
__author__ = "POS Intelligence Team"
__all__ = ["RecommendationModel", "DataProcessor", "DatabaseConnector"]
