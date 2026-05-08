
import json
import urllib.request

URL = 'http://localhost:5001/predict'

def test_obat(obat_id):
    # Load data from _data.tsv
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
        'epochs': 200,
        'learning_rate': 0.001,
        'window_size': 4
    }
    
    req = urllib.request.Request(URL, data=json.dumps(payload).encode('utf-8'),
                                 headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(req) as r:
        res = json.loads(r.read())
        if res['success']:
            d = res['data']
            print(f"Obat ID {obat_id} MAPE: {d['mape']}%")
            print(f"Accuracy: {d['accuracy']}%")
        else:
            print(f"Error: {res['message']}")

if __name__ == "__main__":
    test_obat(1)
