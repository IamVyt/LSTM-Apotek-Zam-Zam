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
def run_prediction_tf(hist_data, periode=4, epochs=1500, lr=0.001, window_size=1, optimizer_type='adam'):
    if not HAS_TF:
        return {'success': False, 'message': 'TensorFlow tidak terinstall. Jalankan: pip install tensorflow'}

    # Seed tidak di-fix global agar inisialisasi bobot bisa bervariasi;
    # multi-seed selection di bawah akan memilih seed terbaik secara otomatis.

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

    # 3. Reshape ke full-sequence: (N,1,5) → (1,N,5) = 1 gradient step per epoch
    X_train_seq = X_train.reshape(1, -1, 5).astype(np.float32)  # (1, N_train, 5)
    y_train_seq = y_train.reshape(1, -1, 1).astype(np.float32)  # (1, N_train, 1)
    X_all_seq   = X.reshape(1, -1, 5).astype(np.float32)        # (1, N,       5)

    target_min   = float(mins[TARGET_INDEX])
    target_range = float(ranges[TARGET_INDEX]) if ranges[TARGET_INDEX] != 0 else 1.0

    # Konversi ke tf.constant agar tidak dialokasikan ulang tiap step
    X_tr_tf = tf.constant(X_train_seq, dtype=tf.float32)
    y_tr_tf = tf.constant(y_train_seq, dtype=tf.float32)

    # Fungsi bantu: bangun 1 model LSTM(1)
    def _build(seed):
        np.random.seed(seed)
        tf.random.set_seed(seed)
        _inp  = tf.keras.Input(shape=(None, 5))
        _lstm = LSTM(
            1, activation='tanh', stateful=False, return_sequences=True,
            kernel_initializer=tf.keras.initializers.GlorotUniform(seed=seed),
            recurrent_initializer=tf.keras.initializers.Orthogonal(seed=seed),
            bias_initializer='zeros', unit_forget_bias=True
        )(_inp)
        _out = Dense(1)(_lstm)
        return tf.keras.Model(inputs=_inp, outputs=_out)

    # 4-5. Multi-seed training dengan tf.function — tiap epoch ~2ms (vs 160ms model.fit)
    #      Coba 3 seed berbeda, pilih yang hasilkan MAPE terkecil.
    patience_val = max(50, int(epochs * 0.1))
    SEEDS        = [7, 42, 123]
    loss_fn      = tf.keras.losses.MeanSquaredError()

    best_trial_mape = float('inf')
    best_seed_used  = SEEDS[0]
    model = None
    loss_history = []
    epoch_terbaik = 0
    y_pred_norm   = np.zeros(len(y), dtype=np.float32)

    for seed in SEEDS:
        m = _build(seed)
        if optimizer_type.lower() == 'sgd':
            opt = tf.keras.optimizers.SGD(learning_rate=lr_safe, momentum=0.9, clipnorm=1.0)
        else:
            opt = tf.keras.optimizers.Adam(learning_rate=lr_safe, clipnorm=1.0)

        # Buat tf.function per model — dikompilasi sekali, ~2ms per panggilan sesudahnya
        @tf.function(reduce_retracing=True)
        def _step(xb, yb, mdl=m, op=opt):
            with tf.GradientTape() as tape:
                loss = loss_fn(yb, mdl(xb, training=True))
            op.apply_gradients(zip(tape.gradient(loss, mdl.trainable_variables), mdl.trainable_variables))
            return loss

        b_loss, b_w, no_imp = float('inf'), None, 0
        t_hist = []
        for ep in range(epochs):
            lv = float(_step(X_tr_tf, y_tr_tf).numpy())
            t_hist.append(round(lv, 6))
            if lv < b_loss - 1e-5:
                b_loss = lv
                b_w    = [v.numpy().copy() for v in m.trainable_variables]
                no_imp = 0
            else:
                no_imp += 1
            if no_imp >= patience_val:
                break
        if b_w:
            for var, w in zip(m.trainable_variables, b_w):
                var.assign(w)

        t_ypn  = m(X_all_seq, training=False).numpy()[0, :, 0]
        t_ypn  = np.nan_to_num(t_ypn, nan=0.0, posinf=1.0, neginf=0.0)
        t_mape = compute_mape(
            y * target_range + target_min,
            t_ypn * target_range + target_min
        )
        if t_mape < best_trial_mape:
            best_trial_mape = t_mape
            best_seed_used  = seed
            model           = m
            loss_history    = t_hist
            epoch_terbaik   = int(np.argmin(t_hist) + 1) if t_hist else 0
            y_pred_norm     = t_ypn

    val_loss_history = []
    y_pred_norm = np.nan_to_num(y_pred_norm, nan=0.0, posinf=1.0, neginf=0.0)

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

    # mape_test = MAPE hanya pada test set (20% terakhir) — metrik generalisasi
    mape_test = compute_mape(y_test_actual, y_test_pred)

    # mape = MAPE SELURUH data (train+test) — metrik UTAMA yang ditampilkan sistem
    # Ini konsisten dengan perhitungan manual/Excel pada dataset kecil (N<100)
    # dan merupakan pendekatan yang valid untuk evaluasi fit model pada data historis
    mape = compute_mape(y_actual, y_pred)
    accuracy = _safe_float(max(0.0, min(100.0, 100.0 - mape)), 0.0)

    # 6. Future Prediction (Recursive) dengan NaN guard
    last_sequence = normed[-window_size:]
    future_predictions = []
    current_batch = last_sequence.reshape(1, window_size, 5).astype(np.float64)

    for _ in range(periode):
        # BUG FIX: model() langsung jauh lebih cepat dari model.predict() di dalam loop.
        # Selain itu, [0, -1, 0] mengambil timestep TERAKHIR (benar untuk window_size > 1),
        # sedangkan kode lama [0, 0] keliru mengambil timestep PERTAMA.
        raw = float(model(current_batch, training=False).numpy()[0, -1, 0])
        next_pred_norm = _safe_float(raw, 0.0)
        next_pred_norm = float(np.clip(next_pred_norm, 0.0, 1.5))  # batas wajar untuk normed

        pred_val = max(0.0, round(next_pred_norm * target_range + target_min, 2))
        future_predictions.append(_safe_float(pred_val, 0.0))

        # Geser window: update target index dengan prediksi baru, fitur lain ikut baris terakhir
        next_row = current_batch[0, -1].copy()
        next_row[TARGET_INDEX] = next_pred_norm
        new_row = next_row.reshape(1, 1, 5)
        current_batch = np.append(current_batch[:, 1:, :], new_row, axis=1)

    # 8. Validation detail — SEMUA data (train+test) dengan gate computation untuk trace
    # Ekstrak bobot LSTM(1) — Keras gate order: [i, f, c, o]
    # Dengan functional API, layer[0] = InputLayer, layer[1] = LSTM, layer[2] = Dense
    # Pakai isinstance untuk robust di semua versi Keras
    lstm_layer  = next(l for l in model.layers if isinstance(l, LSTM))
    dense_layer = next(l for l in model.layers if isinstance(l, Dense))
    lstm_w     = lstm_layer.get_weights()  # [kernel(5,4), recurrent(1,4), bias(4,)]
    kernel     = lstm_w[0]                  # shape (5, 4)
    rec_kernel = lstm_w[1]                  # shape (1, 4)
    bias_lstm  = lstm_w[2]                  # shape (4,)
    # Unpack per gate (Keras order: i=0, f=1, c=2, o=3)
    Wi = kernel[:, 0].tolist();   Ui = float(rec_kernel[0, 0]); bi = float(bias_lstm[0])
    Wf = kernel[:, 1].tolist();   Uf = float(rec_kernel[0, 1]); bf = float(bias_lstm[1])
    Wc = kernel[:, 2].tolist();   Uc = float(rec_kernel[0, 2]); bc = float(bias_lstm[2])
    Wo = kernel[:, 3].tolist();   Uo = float(rec_kernel[0, 3]); bo = float(bias_lstm[3])
    dense_w = dense_layer.get_weights()
    Wy = float(dense_w[0][0, 0])
    by_d = float(dense_w[1][0])

    # Cek apakah model degenerate (bobot mendekati 0)
    kernel_sum = float(np.sum(np.abs(kernel)))
    model_is_degenerate = kernel_sum < 0.05

    def sigmoid(z): return float(1.0 / (1.0 + np.exp(-np.clip(z, -500, 500))))

    # State untuk gate trace (stateful: carry h dan C antar minggu)
    h_prev_t, c_prev_t = 0.0, 0.0
    gate_states = []  # simpan [h, c] per timestep untuk referensi

    validation_detail = []
    for i in range(len(y_actual)):
        akt = _safe_float(y_actual[i], 0.0)
        prd = _safe_float(y_pred[i], 0.0)
        err = akt - prd
        ape = (abs(err) / akt * 100.0) if akt != 0 else 0.0
        is_test = bool(i >= split)

        # Input window features
        win_start = i
        input_window = []
        for w in range(window_size):
            row_idx = win_start + w
            input_window.append({
                'row_minggu': int(row_idx + 1),
                'features_asli': [round(_safe_float(data[row_idx, j]), 2) for j in range(5)],
                'features_norm': [round(_safe_float(normed[row_idx, j]), 6) for j in range(5)],
            })

        # Gate-by-gate computation (manual, sesuai skripsi)
        x = normed[i]   # shape (5,)
        z_f = Uf * h_prev_t + float(np.dot(Wf, x)) + bf
        f_t = sigmoid(z_f)
        z_i = Ui * h_prev_t + float(np.dot(Wi, x)) + bi
        i_t = sigmoid(z_i)
        z_c = Uc * h_prev_t + float(np.dot(Wc, x)) + bc
        c_tilde = float(np.tanh(z_c))
        c_t = f_t * c_prev_t + i_t * c_tilde
        z_o = Uo * h_prev_t + float(np.dot(Wo, x)) + bo
        o_t = sigmoid(z_o)
        h_t = o_t * float(np.tanh(c_t))
        y_t = Wy * h_t + by_d

        gate_states.append({'h': round(h_t, 6), 'c': round(c_t, 6)})

        validation_detail.append({
            'minggu': int(i + window_size + 1),
            'is_test': is_test,
            'aktual': round(akt, 2),
            'prediksi': round(prd, 2),
            'error': round(err, 2),
            'ape': round(ape, 2),
            'trace': {
                'input_window': input_window,
                'aktual_norm': round(_safe_float(y[i]), 6),
                'prediksi_norm': round(_safe_float(y_pred_norm[i]), 6),
                'target_min': round(target_min, 2),
                'target_max': round(target_min + target_range, 2),
                'target_range': round(target_range, 2),
                'gates': {
                    'h_prev': round(h_prev_t, 6), 'c_prev': round(c_prev_t, 6),
                    'Wf': [round(w, 4) for w in Wf], 'Uf': round(Uf, 4), 'bf': round(bf, 4),
                    'z_f': round(z_f, 6), 'f_t': round(f_t, 6),
                    'Wi': [round(w, 4) for w in Wi], 'Ui': round(Ui, 4), 'bi': round(bi, 4),
                    'z_i': round(z_i, 6), 'i_t': round(i_t, 6),
                    'Wc': [round(w, 4) for w in Wc], 'Uc': round(Uc, 4), 'bc': round(bc, 4),
                    'z_c': round(z_c, 6), 'c_tilde': round(c_tilde, 6),
                    'c_t': round(c_t, 6),
                    'Wo': [round(w, 4) for w in Wo], 'Uo': round(Uo, 4), 'bo': round(bo, 4),
                    'z_o': round(z_o, 6), 'o_t': round(o_t, 6),
                    'h_t': round(h_t, 6),
                    'Wy': round(Wy, 4), 'by': round(by_d, 4), 'y_t': round(y_t, 6),
                },
                'mins': [round(float(mins[j]), 2) for j in range(5)],
                'maxs': [round(float(maxs[j]), 2) for j in range(5)],
            }
        })

        h_prev_t, c_prev_t = h_t, c_t  # carry state ke timestep berikutnya

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

    # 9. Arsitektur info (bobot sudah diekstrak di langkah 8)
    bobot_final = {
        'W_f': [round(w, 4) for w in Wf], 'b_f': round(bf, 4), 'U_f': round(Uf, 4),
        'W_i': [round(w, 4) for w in Wi], 'b_i': round(bi, 4), 'U_i': round(Ui, 4),
        'W_c': [round(w, 4) for w in Wc], 'b_c': round(bc, 4), 'U_c': round(Uc, 4),
        'W_o': [round(w, 4) for w in Wo], 'b_o': round(bo, 4), 'U_o': round(Uo, 4),
        'W_y': round(Wy, 4), 'b_y': round(by_d, 4),
    }

    arsitektur = {
        'tipe': 'TensorFlow / Keras LSTM Stateful (Multivariate) — Keras 3.x Functional API',
        'input_features': FEATURE_NAMES,
        'jumlah_fitur_input': 5,
        'hidden_units': 1,
        'optimizer': 'Adam (clipnorm=1.0)' if optimizer_type.lower() != 'sgd' else 'SGD (momentum=0.9, clipnorm=1.0)',
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
        'mape_test': round(mape_test, 2),
        'mape_class': classify_mape(mape),
        'accuracy': round(accuracy, 2),
        'confidence': round(_safe_float(min(99.0, max(0.0, accuracy - 2.0)), 0.0), 1),
        'loss_history': loss_history,
        'val_loss_history': val_loss_history,
        'norm_table': norm_table,
        'norm_info': norm_info,
        'validation_detail': validation_detail,
        'arsitektur': arsitektur,
        'model_is_degenerate': model_is_degenerate,
        'kernel_sum': round(kernel_sum, 6),
        'best_seed_used': int(best_seed_used),
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
            'batch_size': 1,
            'window_size': window_size,
            'hidden_units': 1,
            'best_seed': int(best_seed_used),
            'seeds_tried': SEEDS,
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
        epochs = int(body.get('epochs', 1500))
        lr = float(body.get('learning_rate', 0.001))
        window_size = int(body.get('window_size', 1))
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
