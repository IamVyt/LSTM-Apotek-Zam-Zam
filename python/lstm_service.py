"""
Apotek Zam Zam - Multivariate LSTM Service (TensorFlow/Keras Engine)
Modul Prediksi Persediaan dengan Deep Learning.
Mendukung Multivariate (5 fitur) dan Window Size (Lookback).
"""
import os
import json
import time
import math
import traceback
import numpy as np
from flask import Flask, request, jsonify
from flask_cors import CORS

# Sembunyikan log TensorFlow yang tidak perlu
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '2'

try:
    import tensorflow as tf
    from tensorflow.keras.models import Sequential
    from tensorflow.keras.layers import LSTM, Dense, Dropout
    from tensorflow.keras.optimizers import Adam
    from tensorflow.keras.callbacks import EarlyStopping
    HAS_TF = True
except ImportError:
    HAS_TF = False

app = Flask(__name__)
CORS(app)

# ═══════════════════════════════════════════════════
# KONSTANTA & METRIK
# ═══════════════════════════════════════════════════
MAPE_SANGAT_BAIK = 10.0
FEATURE_NAMES = [
    'Stok Awal Minggu', 'Total Masuk', 'Total Keluar',
    'Stok Akhir Minggu', 'Rata-rata Keluar/Hari'
]
TARGET_INDEX = 2  # Total Keluar

def _safe_float(v, default=0.0):
    try:
        f = float(v)
        if math.isnan(f) or math.isinf(f):
            return float(default)
        return f
    except (TypeError, ValueError):
        return float(default)

def compute_mape(actual, predicted):
    actual = np.asarray(actual, dtype=np.float64)
    predicted = np.asarray(predicted, dtype=np.float64)
    mask = (actual != 0) & np.isfinite(actual) & np.isfinite(predicted)
    if not np.any(mask):
        return 0.0
    val = float(np.mean(np.abs((actual[mask] - predicted[mask]) / actual[mask])) * 100)
    return _safe_float(val, 0.0)

def classify_mape(mape):
    if mape < 10: return 'Sangat Baik (Highly Accurate)'
    if mape < 20: return 'Baik (Good)'
    if mape < 50: return 'Cukup (Reasonable)'
    return 'Kurang Akurat (Inaccurate)'

# ═══════════════════════════════════════════════════
# DATA PREPARATION (WINDOWING)
# ═══════════════════════════════════════════════════
def create_sequences(data, window_size):
    X, y = [], []
    for i in range(len(data) - window_size):
        X.append(data[i:(i + window_size)])
        y.append(data[i + window_size, TARGET_INDEX])
    return np.array(X), np.array(y)

def normalize_minmax(data):
    mins = data.min(axis=0)
    maxs = data.max(axis=0)
    ranges = maxs - mins
    ranges[ranges == 0] = 1.0
    normed = (data - mins) / ranges
    return normed, mins, maxs, ranges

# ═══════════════════════════════════════════════════
# CORE PREDICTION LOGIC
# ═══════════════════════════════════════════════════
def run_prediction_tf(hist_data, periode=4, epochs=200, lr=0.01, window_size=4, optimizer_type='adam'):
    if not HAS_TF:
        return {'success': False, 'message': 'TensorFlow tidak terinstall. Jalankan: pip install tensorflow'}

    # Reproducibility: seed agar hasil training stabil & tidak meledak random
    np.random.seed(42)
    tf.random.set_seed(42)

    start_time = time.time()
    data = np.array(hist_data, dtype=np.float64)
    N = len(data)

    if N < window_size + 1:
        return {'success': False, 'message': f'Data tidak cukup untuk window size {window_size}. Butuh minimal {window_size+1} baris.'}

    # Clamp learning_rate ke rentang aman
    lr_safe = float(np.clip(lr, 1e-5, 0.05))

    # 1. Normalisasi Min-Max
    normed, mins, maxs, ranges = normalize_minmax(data)

    # 2. Build Sequences
    X, y = create_sequences(normed, window_size)
    
    # Split 80/20 chronological
    split = max(1, int(len(X) * 0.8))
    X_train, y_train = X[:split], y[:split]
    X_test, y_test = X[split:], y[split:]

    # 3. Build Model — Lebih compact untuk data farmasi yang biasanya volatil
    model = Sequential([
        LSTM(12, activation='tanh', input_shape=(window_size, 5), return_sequences=False),
        Dropout(0.1),
        Dense(6, activation='relu'),
        Dense(1)
    ])

    # Note: LSTM inherently uses BPTT (Backpropagation Through Time) for gradient calculation.
    if optimizer_type.lower() == 'sgd':
        # SGD (Stochastic Gradient Descent) + Momentum as requested
        opt = tf.keras.optimizers.SGD(learning_rate=lr_safe, momentum=0.9, clipnorm=1.0)
    else:
        # Adam is usually more stable for multivariate time-series
        opt = tf.keras.optimizers.Adam(learning_rate=lr_safe, clipnorm=1.0)
        
    model.compile(optimizer=opt, loss='mae', metrics=['mse'])

    # 4. Training — batch_size = 1 seringkali lebih baik untuk dataset sangat kecil
    batch_size = 1

    callbacks = [
        EarlyStopping(monitor='loss', patience=30, restore_best_weights=True, min_delta=1e-7),
        tf.keras.callbacks.TerminateOnNaN(),
    ]

    fit_kwargs = dict(epochs=epochs, batch_size=batch_size, verbose=0, callbacks=callbacks, shuffle=False)
    if len(X_test) >= 1:
        fit_kwargs['validation_data'] = (X_test, y_test)

    history = model.fit(X_train, y_train, **fit_kwargs)

    loss_history = [_safe_float(l, 0.0) for l in history.history.get('loss', [])]
    val_loss_history = [_safe_float(l, 0.0) for l in history.history.get('val_loss', [])]

    # epoch terbaik = epoch dengan loss training terendah (selaras dgn restore_best_weights)
    epoch_terbaik = int(np.argmin(loss_history) + 1) if loss_history else 0

    # 5. Evaluation pada SELURUH dataset (untuk grafik), metrik dihitung pada test set
    y_pred_norm = model.predict(X, verbose=0).flatten()
    y_pred_norm = np.nan_to_num(y_pred_norm, nan=0.0, posinf=1.0, neginf=0.0)

    target_min = float(mins[TARGET_INDEX])
    target_range = float(ranges[TARGET_INDEX]) if ranges[TARGET_INDEX] != 0 else 1.0

    y_pred = y_pred_norm * target_range + target_min
    y_actual = y * target_range + target_min

    # Test set metrics — guard dari array kosong / NaN
    if len(X_test) >= 1:
        y_test_pred = y_pred[split:]
        y_test_actual = y_actual[split:]
    else:
        y_test_pred = y_pred
        y_test_actual = y_actual

    mae = _safe_float(np.mean(np.abs(y_test_actual - y_test_pred)), 0.0)
    rmse = _safe_float(np.sqrt(np.mean((y_test_actual - y_test_pred) ** 2)), 0.0)
    mape = compute_mape(y_test_actual, y_test_pred)
    accuracy = _safe_float(max(0.0, min(100.0, 100.0 - mape)), 0.0)

    # 6. Future Prediction (Recursive) dengan NaN guard
    last_sequence = normed[-window_size:]
    future_predictions = []
    current_batch = last_sequence.reshape(1, window_size, 5).astype(np.float64)

    for _ in range(periode):
        raw = model.predict(current_batch, verbose=0)[0, 0]
        next_pred_norm = _safe_float(raw, 0.0)
        next_pred_norm = float(np.clip(next_pred_norm, 0.0, 1.5))  # batas wajar untuk normed

        pred_val = max(0.0, round(next_pred_norm * target_range + target_min, 2))
        future_predictions.append(_safe_float(pred_val, 0.0))

        # Geser window: update target index dengan prediksi baru, fitur lain ikut baris terakhir
        next_row = current_batch[0, -1].copy()
        next_row[TARGET_INDEX] = next_pred_norm
        new_row = next_row.reshape(1, 1, 5)
        current_batch = np.append(current_batch[:, 1:, :], new_row, axis=1)

    # 7. Validation detail — HANYA test set (sesuai kontrak: tabel = data validasi/test)
    # PHP akan memetakan tanggal & minggu_ke berdasarkan trainSamples + idx + 1.
    validation_detail = []
    for i in range(split, len(y_actual)):
        akt = _safe_float(y_actual[i], 0.0)
        prd = _safe_float(y_pred[i], 0.0)
        err = akt - prd
        ape = (abs(err) / akt * 100.0) if akt != 0 else 0.0
        validation_detail.append({
            'minggu': int(i + window_size + 1),  # akan ditimpa PHP dengan minggu_ke aktual
            'aktual': round(akt, 2),
            'prediksi': round(prd, 2),
            'error': round(err, 2),
            'ape': round(ape, 2),
        })

    # 8. Norm table & info untuk frontend
    norm_table = []
    for i in range(N):
        row = {'minggu': int(i + 1)}
        for j, fname in enumerate(FEATURE_NAMES):
            row[fname] = {
                'asli': round(_safe_float(data[i, j]), 2),
                'norm': round(_safe_float(normed[i, j]), 6),
            }
        norm_table.append(row)

    norm_info = {}
    for j, fname in enumerate(FEATURE_NAMES):
        norm_info[fname] = {
            'min': round(_safe_float(mins[j]), 2),
            'max': round(_safe_float(maxs[j]), 2),
            'range': round(_safe_float(ranges[j]), 2),
        }

    # 9. Bobot final (LSTM kernel terdiri dari 4 gate: i, f, c, o per Keras spec)
    try:
        lstm_layer = model.layers[0]
        kernel, recurrent_kernel, bias = lstm_layer.get_weights()
        units = lstm_layer.units
        # Urutan Keras: [i, f, c, o]
        Wi = kernel[:, :units].mean(axis=0)
        Wf = kernel[:, units:units*2].mean(axis=0)
        Wc = kernel[:, units*2:units*3].mean(axis=0)
        Wo = kernel[:, units*3:].mean(axis=0)
        bi = float(bias[:units].mean())
        bf = float(bias[units:units*2].mean())
        bc = float(bias[units*2:units*3].mean())
        bo = float(bias[units*3:].mean())
        bobot_final = {
            'W_f': [_safe_float(Wf.mean())], 'b_f': _safe_float(bf),
            'W_i': [_safe_float(Wi.mean())], 'b_i': _safe_float(bi),
            'W_c': [_safe_float(Wc.mean())], 'b_c': _safe_float(bc),
            'W_o': [_safe_float(Wo.mean())], 'b_o': _safe_float(bo),
        }
    except Exception:
        bobot_final = {
            'W_f': [0.0], 'b_f': 0.0, 'W_i': [0.0], 'b_i': 0.0,
            'W_c': [0.0], 'b_c': 0.0, 'W_o': [0.0], 'b_o': 0.0,
        }

    arsitektur = {
        'tipe': 'TensorFlow / Keras LSTM (Multivariate)',
        'input_features': FEATURE_NAMES,
        'jumlah_fitur_input': 5,
        'hidden_units': 16,
        'optimizer': 'Adam (clipnorm=1.0)',
        'loss_function': 'Mean Squared Error (MSE)',
        'window_size': window_size,
        'bobot_final': bobot_final,
    }

    train_test_split = {
        'total_samples': int(len(X)),
        'train_samples': int(split),
        'test_samples': int(len(X) - split),
        'train_ratio': 80.0,
        'test_ratio': 20.0,
    }

    # Rekomendasi
    avg_hist = _safe_float(np.mean(data[:, TARGET_INDEX]), 0.0)
    avg_pred = _safe_float(np.mean(future_predictions), 0.0) if future_predictions else 0.0
    total_pred = _safe_float(np.sum(future_predictions), 0.0)

    if avg_hist > 0 and avg_pred > avg_hist * 1.1:
        rek_status, rek_text = 'TINGGI', f'Permintaan NAIK. Siapkan stok ±{int(total_pred * 1.2)} unit.'
    elif avg_hist > 0 and avg_pred < avg_hist * 0.9:
        rek_status, rek_text = 'RENDAH', f'Permintaan TURUN. Stok ±{int(total_pred * 1.1)} unit cukup.'
    else:
        rek_status, rek_text = 'NORMAL', f'Permintaan STABIL. Stok ±{int(total_pred * 1.15)} unit disarankan.'

    training_time = round(time.time() - start_time, 2)

    return {
        'success': True,
        'predictions': future_predictions,
        'mae': round(mae, 4),
        'rmse': round(rmse, 4),
        'mape': round(mape, 2),
        'mape_class': classify_mape(mape),
        'accuracy': round(accuracy, 2),
        'confidence': round(_safe_float(min(99.0, max(0.0, accuracy - 2.0)), 0.0), 1),
        'loss_history': loss_history,
        'val_loss_history': val_loss_history,
        'norm_table': norm_table,
        'norm_info': norm_info,
        'validation_detail': validation_detail,
        'arsitektur': arsitektur,
        'train_test_split': train_test_split,
        'rekomendasi': {
            'status': rek_status, 'text': rek_text,
            'total_kebutuhan': int(math.ceil(total_pred)),
            'avg_per_minggu': round(avg_pred, 2),
        },
        'model_params': {
            'engine': 'TensorFlow 2.x',
            'epochs_configured': int(epochs),
            'epochs_actual': len(loss_history),
            'epoch_terbaik': epoch_terbaik,
            'learning_rate': lr_safe,
            'batch_size': batch_size,
            'window_size': window_size,
            'training_time_seconds': training_time,
        }
    }

# ═══════════════════════════════════════════════════
# FLASK ENDPOINTS
# ═══════════════════════════════════════════════════
@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'ok',
        'engine': 'TensorFlow' if HAS_TF else 'NumPy (Fallback)',
        'tf_version': tf.__version__ if HAS_TF else None,
        'device': 'GPU' if HAS_TF and tf.config.list_physical_devices('GPU') else 'CPU'
    })

@app.route('/predict', methods=['POST'])
def predict():
    try:
        body = request.get_json()
        if not body: return jsonify({'success': False, 'message': 'No body'}), 400

        hist = body.get('historical_data', [])
        periode = int(body.get('periode', 4))
        epochs = int(body.get('epochs', 200))
        lr = float(body.get('learning_rate', 0.01))
        window_size = int(body.get('window_size', 4))
        optimizer_type = str(body.get('optimizer', 'adam'))

        if not hist or len(hist) < 5:
            return jsonify({'success': False, 'message': 'Data minimal 5 minggu'}), 400

        result = run_prediction_tf(hist, periode, epochs, lr, window_size, optimizer_type)
        # Bungkus dalam {success, data} sesuai kontrak yang dipakai api/predictions.php
        if not result.get('success', False):
            return jsonify(result), 400
        payload = {k: v for k, v in result.items() if k != 'success'}
        return jsonify({'success': True, 'data': payload})

    except Exception as e:
        traceback.print_exc()
        return jsonify({'success': False, 'message': str(e)}), 500

if __name__ == '__main__':
    print("="*60)
    print("  Apotek Zam Zam - TensorFlow LSTM Service")
    if HAS_TF:
        print(f"  TensorFlow v{tf.__version__} Ready")
    else:
        print("  WARNING: TensorFlow NOT FOUND. Please run: pip install tensorflow")
    print("="*60)
    app.run(host='0.0.0.0', port=5001, debug=False)
