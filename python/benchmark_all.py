"""
Benchmark singkat: test semua obat × beberapa kombinasi hyperparameter
untuk lihat MAPE realistis & kombinasi terbaik per obat.
Hit endpoint /predict yang sudah jalan.
"""
import json
import os
import urllib.request

URL = 'http://localhost:5000/predict'

OBAT_NAMES = {1: 'AMLODIPIN', 2: 'CANDESARTAN', 3: 'IBUPROFEN', 4: 'PARASETAMOL', 5: 'PIROXICAM'}

# Konfigurasi yang akan dicoba
configs = [
    {'epochs': 200, 'learning_rate': 0.01,  'window_size': 1},
    {'epochs': 200, 'learning_rate': 0.001, 'window_size': 1},
    {'epochs': 300, 'learning_rate': 0.001, 'window_size': 4},
    {'epochs': 300, 'learning_rate': 0.001, 'window_size': 8},
    {'epochs': 500, 'learning_rate': 0.001, 'window_size': 4},
]

def load_all_hist():
    """Baca python/_data.tsv hasil export MySQL."""
    path = os.path.join(os.path.dirname(__file__), '_data.tsv')
    by_obat = {}
    with open(path, encoding='utf-8') as f:
        next(f)  # skip header
        for line in f:
            parts = line.rstrip('\n').split('\t')
            if len(parts) < 6:
                continue
            obat_id = int(parts[0])
            row = [float(parts[1]), float(parts[2]), float(parts[3]),
                   float(parts[4]), float(parts[5])]
            by_obat.setdefault(obat_id, []).append(row)
    return by_obat

ALL_HIST = load_all_hist()
obats = [{'id': k, 'nama_obat': OBAT_NAMES.get(k, f'OBAT-{k}')} for k in sorted(ALL_HIST.keys())]

def fetch_hist(obat_id):
    return ALL_HIST.get(obat_id, [])

def call_predict(payload):
    req = urllib.request.Request(URL, data=json.dumps(payload).encode('utf-8'),
                                 headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            return json.loads(r.read())
    except Exception as e:
        return {'success': False, 'message': str(e)}

print(f"\n{'OBAT':<14} {'EPOCH':>6} {'LR':>7} {'WIN':>4}  {'MAPE':>7} {'RMSE':>9} {'KELAS':<25}")
print("-" * 90)

best_per_obat = {}

for obat in obats:
    hist = fetch_hist(obat['id'])
    if len(hist) < 5:
        print(f"{obat['nama_obat']:<14} skip (data < 5)")
        continue

    best = None
    for cfg in configs:
        payload = {'historical_data': hist, 'periode': 4, **cfg}
        resp = call_predict(payload)
        if not resp.get('success'):
            print(f"{obat['nama_obat']:<14} ERROR: {resp.get('message')}")
            continue
        d = resp.get('data', resp)
        mape = d.get('mape', 999)
        rmse = d.get('rmse', 0)
        klas = d.get('mape_class', '-')
        print(f"{obat['nama_obat']:<14} {cfg['epochs']:>6} {cfg['learning_rate']:>7} {cfg['window_size']:>4}  "
              f"{mape:>6.2f}% {rmse:>9.2f} {klas:<25}")
        if best is None or mape < best['mape']:
            best = {'mape': mape, 'rmse': rmse, 'cfg': cfg, 'klas': klas}
    best_per_obat[obat['nama_obat']] = best
    print()

print("\n" + "=" * 90)
print("RINGKASAN — KONFIGURASI TERBAIK PER OBAT")
print("=" * 90)
for nama, b in best_per_obat.items():
    if b:
        print(f"{nama:<14} MAPE={b['mape']:.2f}% ({b['klas']}) — "
              f"epochs={b['cfg']['epochs']}, lr={b['cfg']['learning_rate']}, win={b['cfg']['window_size']}")
