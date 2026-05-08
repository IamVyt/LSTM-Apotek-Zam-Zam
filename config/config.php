<?php
/**
 * Application Configuration
 * Apotek Zam Zam - Pharmacy Drug Inventory Prediction System
 */

// Application Constants
define('APP_NAME', 'Apotek Zam Zam');
define('APP_VERSION', '1.0.0');
define('APP_DESCRIPTION', 'Sistem Prediksi Stok Obat Apotek menggunakan LSTM');

// Base URL - adjust to match your server setup
define('BASE_URL', '/pharmapredictt');

// Pagination
define('ITEMS_PER_PAGE', 12);

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Python LSTM Service URL
define('PYTHON_LSTM_URL', 'http://localhost:5001');

// LSTM Default Parameters (for Python TensorFlow/Keras LSTM)
define('LSTM_SEQUENCE_LENGTH', 8);   // 8 weeks lookback
define('LSTM_HIDDEN_UNITS', 32);     // reduced from 64 for faster training
define('LSTM_EPOCHS', 50);           // reduced from 100; EarlyStopping stops sooner anyway
define('LSTM_LEARNING_RATE', 0.001);
