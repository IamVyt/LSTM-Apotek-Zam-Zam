
import json
import urllib.request
import os

URL = 'http://localhost:5001/predict'

def test_config(obat_id, epochs, lr, win, opt):
    hist = []
    with open('_data.tsv', encoding='utf-8') as f:
        next(f)
        for line in f:
            parts = line.strip().split('\t')
            if int(parts[0]) == obat_id:
                hist.append([float(p) for p in parts[1:]])
    
    payload = {
        'historical_data': hist,
        'periode': 4,
        'epochs': epochs,
        'learning_rate': lr,
        'window_size': win,
        'optimizer': opt
    }
    
    req = urllib.request.Request(URL, data=json.dumps(payload).encode('utf-8'),
                                 headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            res = json.loads(r.read())
            if res['success']:
                return res['data']['mape']
    except Exception as e:
        print(f"Error: {e}")
    return 999

if __name__ == "__main__":
    configs = [
        (300, 0.001, 4, 'adam'),
        (300, 0.01,  4, 'adam'),
        (500, 0.001, 8, 'adam'),
        (300, 0.01,  1, 'adam'),
        (300, 0.01,  4, 'sgd'),
    ]
    
    print(f"{'EPOCHS':<8} {'LR':<8} {'WIN':<5} {'OPT':<6} {'MAPE':<10}")
    for e, l, w, o in configs:
        mape = test_config(1, e, l, w, o)
        print(f"{e:<8} {l:<8} {w:<5} {o:<6} {mape:<10.2f}%")
