
import json
import urllib.request

URL = 'http://localhost:5001/predict'

def test_all_drugs():
    all_data = {}
    with open('_data.tsv', encoding='utf-8') as f:
        next(f)
        for line in f:
            parts = line.strip().split('\t')
            oid = int(parts[0])
            all_data.setdefault(oid, []).append([float(p) for p in parts[1:]])
    
    print(f"{'ID':<4} {'NAME':<15} {'MAPE':<10} {'ACCURACY':<10}")
    names = {1: 'AMLODIPIN', 2: 'CANDESARTAN', 3: 'IBUPROFEN', 4: 'PARASETAMOL', 5: 'PIROXICAM'}
    
    for oid, hist in all_data.items():
        payload = {
            'historical_data': hist,
            'periode': 4,
            'epochs': 300,
            'learning_rate': 0.001,
            'window_size': 4
        }
        req = urllib.request.Request(URL, data=json.dumps(payload).encode('utf-8'),
                                     headers={'Content-Type': 'application/json'})
        try:
            with urllib.request.urlopen(req) as r:
                res = json.loads(r.read())
                if res['success']:
                    d = res['data']
                    print(f"{oid:<4} {names.get(oid, '???'):<15} {d['mape']:<10.2f}% {d['accuracy']:<10.2f}%")
        except:
            pass

if __name__ == "__main__":
    test_all_drugs()
