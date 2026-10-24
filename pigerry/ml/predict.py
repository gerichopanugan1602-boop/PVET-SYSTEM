"""Fixed JSON stdin/stdout bridge used by PHP. Never accepts an uploaded model."""
import json
import os
import sys
import time
from pathlib import Path

from domain import normalize, recommend, require_checkbox_encoding, require_confirmation, validate_record
from pipeline import file_hash, load_bundle, load_json, matrix, unseen_categories

# All files live in this same folder (ml/). pipeline.py's own ROOT points one folder up,
# so it is not used here.
ROOT = Path(__file__).resolve().parent


def demo_predict(record, bundle, drafts):
    if (bundle.get('stage') != 'frozen_research' or bundle.get('version') != drafts.get('model_version')
            or drafts.get('approved') is not False or drafts.get('demonstration_only') is not True
            or not isinstance(drafts.get('version'), str) or not drafts['version'].strip()
            or not isinstance(drafts.get('clusters'), dict) or set(drafts['clusters']) != {str(k) for k in range(5)}
            or not all(isinstance(v, str) and v.strip() for v in drafts['clusters'].values())
            or not isinstance(drafts.get('notice'), str) or not drafts['notice'].strip()
            or not isinstance(drafts.get('recommendation_kinds'), dict)
            or any(drafts['recommendation_kinds'].get(label) not in {'support', 'advice', 'agency_activity'}
                   for text in drafts['clusters'].values() for label in text.splitlines())):
        raise ValueError('Demo configuration must match the frozen research model and remain unapproved.')
    require_confirmation(bundle['confirmation'])
    errors = validate_record(record, confirmation=bundle['confirmation'])
    if errors:
        raise ValueError('; '.join(errors))
    x = matrix([record], bundle['preprocessing'], bundle['algorithm'], bundle['weighted'], bundle['confirmation'])
    cluster = int(bundle['model'].predict(x)[0])
    if str(cluster) not in drafts['clusters']:
        raise ValueError(f'Unknown cluster ID: {cluster}. Review the recommendation mapping.')
    text = drafts['clusters'][str(cluster)]
    return {'cluster_id': cluster, 'model_version': bundle['version'], 'draft_version': drafts['version'],
            'demonstration_only': True, 'production_approved': False, 'notice': drafts['notice'],
            'draft_text': text, 'suggested_services': [],
            'recommendations': [{'label': label, 'kind': drafts['recommendation_kinds'][label]} for label in text.splitlines()],
            'assessment_flags': ['PVET criteria and individual eligibility checks are pending.'],
            'unseen_categories': unseen_categories(record, bundle['preprocessing'])}


def predict(record, checks, bundle):
    if bundle.get('stage') != 'active':
        raise ValueError('Model is not active. PVET release review is required.')
    require_confirmation(bundle['confirmation'])
    errors = validate_record(record, confirmation=bundle['confirmation'])
    if errors:
        raise ValueError('; '.join(errors))
    if bundle['rules'].get('model_version') != bundle['version']:
        raise ValueError('Model and rule versions do not match.')
    started = time.perf_counter()
    data = matrix([record], bundle['preprocessing'], bundle['algorithm'], bundle['weighted'], bundle['confirmation'])
    cluster = int(bundle['model'].predict(data)[0])
    output = recommend(record, cluster, bundle['rules'], checks, unseen_categories(record, bundle['preprocessing']))
    output.update(cluster_id=cluster, model_version=bundle['version'], rule_version=bundle['rules']['version'], seconds=time.perf_counter() - started)
    return output


def main():
    try:
        raw = sys.stdin.read(2_000_001)
        if len(raw) > 2_000_000:
            raise ValueError('Input is too large.')
        payload = json.loads(raw)
        if not isinstance(payload, dict):
            raise ValueError('Expected a JSON object.')
        command = sys.argv[1] if len(sys.argv) > 1 else 'predict'
        c = load_json(ROOT / 'confirm.json')
        try:
            require_checkbox_encoding(c)
            confirmed = c
        except ValueError:
            confirmed = None
        if command == 'validate-batch':
            records = payload.get('records')
            if not isinstance(records, list) or not records or len(records) > 5000 or any(not isinstance(r, dict) for r in records):
                raise ValueError('Expected between 1 and 5000 farmer objects.')
            errors = []
            seen = set()
            for i, record in enumerate(records, 2):
                errors.extend(f'Row {i}: {error}' for error in validate_record(record, partial=True, confirmation=confirmed))
                identifier = normalize(record.get('ID'))
                if not identifier or identifier in seen:
                    errors.append(f'Row {i}: missing or repeated ID')
                seen.add(identifier)
            if errors:
                raise ValueError('; '.join(errors[:20]))
            result = {'records': [{k: normalize(v) for k, v in r.items()} for r in records], 'encoding_confirmed': confirmed is not None}
        else:
            record = payload.get('record')
            if not isinstance(record, dict):
                raise ValueError('Expected record object.')
            if command == 'validate':
                errors = validate_record(record, partial=True, confirmation=confirmed)
                if errors:
                    raise ValueError('; '.join(errors))
                result = {'record': {k: normalize(v) for k, v in record.items()}, 'encoding_confirmed': confirmed is not None}
            elif command == 'demo':
                model_path = ROOT / 'frozen.pkl'
                drafts = load_json(ROOT / 'demo-suggestions.json')
                if not model_path.is_file() or file_hash(model_path) != drafts.get('model_sha256'):
                    raise ValueError('Demo model fingerprint does not match. Review draft mapping before using a different model.')
                result = demo_predict(record, load_bundle(model_path), drafts)
            elif command == 'predict':
                model_path = Path(os.environ.get('PVET_MODEL_PATH', str(ROOT / 'models/active.pkl')))
                if not model_path.exists():
                    raise ValueError('No approved active model. Complete data confirmation, model validation and PVET rule review first.')
                checks = payload.get('checks', {})
                if not isinstance(checks, dict) or any(type(v) is not bool for v in checks.values()):
                    raise ValueError('Individual checks must be Boolean values.')
                result = predict(record, checks, load_bundle(model_path))
            else:
                raise ValueError('Unknown bridge command.')
        print(json.dumps({'ok': True, **result}, ensure_ascii=True, allow_nan=False))
        return 0
    except Exception as e:
        # No traceback or record contents in browser responses; stderr is for server logs.
        print(json.dumps({'ok': False, 'error': str(e)}, ensure_ascii=True))
        return 2


if __name__ == '__main__':
    sys.exit(main())