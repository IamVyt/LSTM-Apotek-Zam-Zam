import csv
import numpy as np
import tensorflow as tf
from tensorflow.keras.models import Sequential
from tensorflow.keras.layers import LSTM, Dense

# Read CSV manually
data = []
with open('../Data_Obat_Mingguan.csv', 'r') as f:
    reader = csv.reader(f)
    next(reader) # skip header
    for row in reader:
        if row[3] == 'AMLODIPIN':
            # stok awal, total masuk, total keluar, stok akhir, rata-rata keluar
            data.append([float(row[5]), float(row[6]), float(row[7]), float(row[8]), float(row[9])])

hist_data = np.array(data)

def normalize_minmax(data):
    mins = data.min(axis=0)
    maxs = data.max(axis=0)
    ranges = maxs - mins
    ranges[ranges == 0] = 1.0
    return (data - mins) / ranges, mins, maxs, ranges

def create_sequences(data, window_size, target_idx=2):
    X, y = [], []
    for i in range(len(data) - window_size):
        X.append(data[i:(i + window_size)])
        y.append(data[i + window_size, target_idx])
    return np.array(X), np.array(y)

window_size = 1
normed, mins, maxs, ranges = normalize_minmax(hist_data)
X, y = create_sequences(normed, window_size)

split = max(1, int(len(X) * 0.8))
X_train, y_train = X[:split], y[:split]
X_test, y_test = X[split:], y[split:]

def compute_mape(actual, predicted):
    actual = np.asarray(actual, dtype=np.float64)
    predicted = np.asarray(predicted, dtype=np.float64)
    mask = (actual != 0)
    return float(np.mean(np.abs((actual[mask] - predicted[mask]) / actual[mask])) * 100)

for units in [32, 50, 64, 128]:
    tf.random.set_seed(42)
    np.random.seed(42)
    model = Sequential([
        LSTM(units, activation='tanh', input_shape=(window_size, 5), return_sequences=False),
        Dense(1)
    ])
    model.compile(optimizer=tf.keras.optimizers.Adam(learning_rate=0.001, clipnorm=1.0), loss='mse')
    model.fit(X_train, y_train, epochs=1500, batch_size=1, verbose=0, shuffle=False)
    
    y_pred_norm = model.predict(X, verbose=0).flatten()
    y_pred = y_pred_norm * ranges[2] + mins[2]
    y_actual = y * ranges[2] + mins[2]
    
    mape = compute_mape(y_actual[split:], y_pred[split:])
    print(f'Units: {units}, MAPE: {mape:.2f}%')
