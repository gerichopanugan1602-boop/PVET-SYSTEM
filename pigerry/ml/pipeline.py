"""Offline audit, clustering comparison, frozen validation and release commands."""
import argparse
import collections
import csv
import datetime
import hashlib
import json
import os
import pickle
import random
import sys
import time
import zipfile
from pathlib import Path
from xml.etree import ElementTree as ET

from domain import CHECKBOXES, CHECKBOX_GROUPS, FEATURES, FEATURE_GROUPS, FIELDS, ORDER, SERVICES, categorical_distance, normalize, recommend, require_checkbox_encoding, require_confirmation, validate_record, validate_rules

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'vendor'))
NS = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
SEED = 42


def load_json(path):
    return json.loads(Path(path).read_text(encoding='utf-8-sig'))


def file_hash(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def save_json(path, value):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2, allow_nan=False), encoding='utf-8')


def read_xlsx(path):
    """Read values from the first sheet without Excel or a new workbook dependency."""
    with zipfile.ZipFile(path) as z:
        shared = []
        if 'xl/sharedStrings.xml' in z.namelist():
            shared = [''.join(item.itertext()) for item in ET.fromstring(z.read('xl/sharedStrings.xml'))]
        workbook = ET.fromstring(z.read('xl/workbook.xml'))
        sheet = workbook.find('s:sheets/s:sheet', NS)
        rel_id = sheet.attrib['{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id']
        rels = ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))
        target = next(r.attrib['Target'] for r in rels if r.attrib['Id'] == rel_id)
        sheet_path = target.lstrip('/') if target.startswith('/') else 'xl/' + target
        rows = []
        for row in ET.fromstring(z.read(sheet_path)).findall('s:sheetData/s:row', NS):
            values = {}
            for cell in row:
                ref = ''.join(c for c in cell.attrib['r'] if c.isalpha())
                index = 0
                for letter in ref:
                    index = index * 26 + ord(letter) - 64
                v = cell.find('s:v', NS)
                value = v.text if v is not None else None
                if cell.find('s:f', NS) is not None:
                    raise ValueError(f'Formula cell {cell.attrib["r"]} requires independently verified cached values.')
                if cell.attrib.get('t') == 's' and value is not None:
                    value = shared[int(value)]
                elif cell.attrib.get('t') == 'inlineStr':
                    value = ''.join(cell.find('s:is', NS).itertext())
                values[index - 1] = value
            rows.append(values)
        if not rows:
            raise ValueError('Workbook has no rows.')
        headers = [rows[0].get(i) for i in range(max(rows[0]) + 1)]
        if headers != ORDER:
            raise ValueError('Workbook headers differ from the approved 76-field dictionary.')
        return [{key: row.get(i) for i, key in enumerate(headers)} for row in rows[1:] if any(v is not None for v in row.values())]


def read_csv(path):
    with Path(path).open(encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f)
        if reader.fieldnames != ORDER:
            raise ValueError('CSV headers must match the 76-field dictionary exactly.')
        records = list(reader)
    if any(None in r for r in records):
        raise ValueError('CSV has extra cells.')
    return records


def write_csv(path, records, fields=ORDER):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open('w', encoding='utf-8-sig', newline='') as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(records)


def audit(source, out, confirmation):
    records = read_xlsx(source) if Path(source).suffix.lower() == '.xlsx' else read_csv(source)
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    try:
        require_checkbox_encoding(confirmation)
        confirmed = True
    except ValueError:
        confirmed = False
    cleaned, changes, issues = [], [], []
    for row_number, row in enumerate(records, 2):
        record = {key: normalize(value) for key, value in row.items()}
        for key, value in row.items():
            if value != record[key]:
                changes.append({'row': row_number, 'field': key, 'original': value, 'working_value': record[key], 'reason': 'Trim whitespace / preserve blank as unknown; no semantic recoding'})
        errors = validate_record(record, partial=True, confirmation=confirmation if confirmed else None)
        issues.extend({'row': row_number, 'id': record['ID'], 'issue': e} for e in errors)
        cleaned.append(record)
    missing = {k: sum(r[k] is None for r in cleaned) for k in ORDER}
    frequencies = {k: dict(collections.Counter(r[k] if r[k] is not None else '<MISSING>' for r in cleaned).most_common()) for k in ORDER}
    ids = collections.defaultdict(list)
    profiles = collections.defaultdict(list)
    for number, record in enumerate(cleaned, 2):
        ids[record['ID']].append(number)
        profiles[tuple(record[k] for k in ORDER if k != 'ID')].append(number)
    repeated_ids = {k: v for k, v in ids.items() if k is not None and len(v) > 1}
    repeated_profiles = [v for v in profiles.values() if len(v) > 1]
    spellings = {}
    for key in ('BARANGAY', 'OTHER'):
        groups = collections.defaultdict(set)
        for record in cleaned:
            if record[key]:
                groups[' '.join(record[key].casefold().split())].add(record[key])
        spellings[key] = [sorted(values) for values in groups.values() if len(values) > 1]
    summary = {'source': str(Path(source).resolve()), 'source_sha256': hashlib.sha256(Path(source).read_bytes()).hexdigest(), 'rows': len(cleaned), 'columns': len(ORDER), 'encoding_confirmed': confirmed, 'missing_by_field': missing, 'invalid_or_contradictory_cells': len(issues), 'repeated_ids': repeated_ids, 'identical_profiles_excluding_id': repeated_profiles, 'constant_fields': [k for k in ORDER if len(frequencies[k]) == 1], 'possible_spelling_variants': spellings, 'training_status': 'Requires approved resolutions and PVET confirmation', 'notes': ['Identical profiles do not prove duplicate farmers.', 'Unconfirmed checkbox frequencies are raw code counts, not selected-choice prevalence.', 'No invalid record was deleted, imputed or semantically corrected.', 'Earlier cleaning / imputation provenance is not established by the supplied filename.']}
    if confirmed:
        summary['notes'][1] = f"Checkbox encoding confirmed: {confirmation['checkbox_selected']} = selected; {confirmation['checkbox_unselected']} = not selected. Row identity and processing provenance require separate review."
        summary['encoding_confirmation'] = {k: confirmation.get(k) for k in ('checkbox_selected', 'checkbox_unselected', 'confirmed_by', 'confirmed_on', 'notes')}
    write_csv(out / 'working.csv', cleaned)
    write_csv(out / 'cleaning_log.csv', changes, ['row', 'field', 'original', 'working_value', 'reason'])
    write_csv(out / 'issues.csv', issues, ['row', 'id', 'issue'])
    save_json(out / 'audit.json', summary)
    save_json(out / 'raw_frequencies.json', frequencies)
    dictionary = json.loads(json.dumps(FIELDS))
    if confirmed:
        for key in CHECKBOXES:
            dictionary[key]['codes'] = {str(confirmation['checkbox_selected']): 'Selected', str(confirmation['checkbox_unselected']): 'Not selected'}
    save_json(out / 'field_dictionary.json', dictionary)
    write_csv(out / 'field_dictionary.csv', [{'field': k, 'meaning': v['label'], 'type': v['type'], 'allowed_codes': json.dumps(v['codes'], ensure_ascii=False), 'use': v['use'], 'missing_policy': v['missing_policy']} for k, v in dictionary.items()], ['field', 'meaning', 'type', 'allowed_codes', 'use', 'missing_policy'])
    lines = ['# Dataset audit', '', f"Rows: {len(cleaned)}. Columns: {len(ORDER)}.", f"Checkbox encoding confirmed: {confirmed}.", f"Missing cells: {sum(missing.values())}. Validation issues: {len(issues)}.", f"Repeated IDs: {len(repeated_ids)}. Repeated profile groups: {len(repeated_profiles)}.", '', '## Limits', *['- ' + n for n in summary['notes']], '', '## Raw category summaries', '', 'Checkbox columns intentionally remain uninterpreted until PVET confirms their coding.']
    if confirmed:
        lines[-1] = 'Checkbox selected-choice counts use the confirmed encoding; they do not establish current disease or service eligibility.'
        lines.extend(['', '## Checkbox selections', '', '|Field|Selected|Not selected|', '|---|---:|---:|', *[f"|{key}|{frequencies[key].get(str(confirmation['checkbox_selected']), 0)}|{frequencies[key].get(str(confirmation['checkbox_unselected']), 0)}|" for key in ORDER if key in CHECKBOXES]])
    for key in CATEGORICAL_SUMMARY:
        lines.extend(['', '### ' + FIELDS[key]['label'], *[f"- {FIELDS[key]['codes'].get(code, 'INVALID / MISSING ' + code)}: {count}" for code, count in frequencies[key].items()]])
    (out / 'audit.md').write_text('\n'.join(lines), encoding='utf-8')
    save_json(ROOT / 'config' / 'fields.json', dictionary)
    return summary


CATEGORICAL_SUMMARY = ['MUNICIPALITY', 'NUMSWINE', 'INCOME', 'FEEDPRAC', 'HOUSING', 'MORTALITY', 'ASF_OUTBREAK', 'VET_CONSULT', 'DISINFECT_FREQ', 'VACCINATED', 'VET_ACCESS', 'SATISFACTION', 'USE_SYSTEM']


def prepared_rows(path, confirmation):
    require_confirmation(confirmation)
    rows = read_csv(path)
    bad = [(i + 2, validate_record(r, partial=True, confirmation=confirmation)) for i, r in enumerate(rows)]
    bad = [(i, errors) for i, errors in bad if errors]
    if bad:
        raise ValueError(f'Training blocked: {len(bad)} rows need resolution. First issues: {bad[:3]}')
    if any(not r['ID'] for r in rows):
        raise ValueError('Missing farmer IDs must be resolved before splitting.')
    ids = collections.Counter(r['ID'] for r in rows)
    if any(n > 1 for n in ids.values()):
        raise ValueError('Repeated IDs must be reviewed and resolved before training.')
    return rows


def group_split(rows):
    """Union repeated IDs and identical full profiles; no group crosses holdout."""
    from sklearn.model_selection import GroupShuffleSplit
    parents = list(range(len(rows)))
    def find(i):
        while parents[i] != i:
            parents[i] = parents[parents[i]]
            i = parents[i]
        return i
    seen = {}
    for i, row in enumerate(rows):
        for key in [('id', row['ID']), ('profile', tuple(normalize(row[k]) for k in ORDER if k != 'ID'))]:
            if key in seen:
                parents[find(i)] = find(seen[key])
            seen[key] = i
    groups = [find(i) for i in range(len(rows))]
    if len(set(groups)) < 3:
        raise ValueError('Need at least three independent groups for development and holdout.')
    train, holdout = next(GroupShuffleSplit(n_splits=1, test_size=.2, random_state=SEED).split(rows, groups=groups))
    return train.tolist(), holdout.tolist()


def fit_preprocessor(rows):
    modes = {}
    observed = {}
    for key in FEATURES:
        counts = collections.Counter(normalize(r.get(key)) for r in rows if normalize(r.get(key)) is not None)
        if not counts:
            raise ValueError(f'{key}: entirely missing in development data.')
        modes[key] = sorted(counts, key=lambda c: (-counts[c], c))[0]
        observed[key] = sorted(counts)
    return {'features': FEATURES, 'modes': modes, 'observed': observed, 'categories': {k: list(FIELDS[k]['codes']) for k in FEATURES}}


def unseen_categories(record, prep):
    return [k for k in prep['features'] if normalize(record.get(k)) not in prep['observed'][k]]


def categorical_matrix(rows, prep):
    import numpy as np
    return np.asarray([[normalize(r.get(k)) or prep['modes'][k] for k in prep['features']] for r in rows], dtype=str)


def numeric_matrix(rows, prep, weighted, confirmation):
    import numpy as np
    data = categorical_matrix(rows, prep)
    columns, owners = [], []
    for i, key in enumerate(prep['features']):
        group = next(g for g, fields in FEATURE_GROUPS.items() if key in fields)
        if key in CHECKBOXES:
            columns.append((data[:, i] == str(confirmation['checkbox_selected'])).astype(float))
            owners.append(group)
        else:
            for code in prep['categories'][key]:
                columns.append((data[:, i] == code).astype(float))
                owners.append(group)
    result = np.column_stack(columns)
    if weighted:
        sizes = collections.Counter(owners)
        result *= np.asarray([1 / sizes[g] ** .5 for g in owners])
    return result


def kmodes_weights(weighted):
    import numpy as np
    return np.asarray([1 / len(next(fields for fields in FEATURE_GROUPS.values() if k in fields)) if weighted else 1 for k in FEATURES])


def make_model(algorithm, k, weighted, seed):
    if algorithm == 'kmeans':
        from sklearn.cluster import KMeans
        return KMeans(n_clusters=k, n_init=10, random_state=seed)
    from kmodes.kmodes import KModes
    from functools import partial
    return KModes(n_clusters=k, init='Huang', n_init=5, random_state=seed, cat_dissim=partial(categorical_distance, weights=kmodes_weights(weighted)))


def matrix(rows, prep, algorithm, weighted, confirmation):
    return numeric_matrix(rows, prep, weighted, confirmation) if algorithm == 'kmeans' else categorical_matrix(rows, prep)


def silhouette(data, labels, algorithm, weighted):
    import numpy as np
    from sklearn.metrics import silhouette_score
    if len(set(labels)) < 2 or len(set(labels)) >= len(labels):
        return None
    if algorithm == 'kmeans':
        return float(silhouette_score(data, labels))
    # ponytail: O(n²) distances on at most 500 evaluation rows; sample larger datasets.
    weights = kmodes_weights(weighted)
    distances = np.zeros((len(data), len(data)))
    for i in range(len(data)):
        distances[i] = ((data != data[i]) * weights).sum(axis=1) / weights.sum()
    return float(silhouette_score(distances, labels, metric='precomputed'))


def profiles(rows, labels, confirmation=None):
    output = {}
    dictionary = json.loads(json.dumps(FIELDS))
    if confirmation is not None:
        for key in CHECKBOXES:
            dictionary[key]['codes'] = {str(confirmation['checkbox_selected']): 'Selected', str(confirmation['checkbox_unselected']): 'Not selected'}
    for cluster in sorted(set(int(v) for v in labels)):
        members = [r for r, label in zip(rows, labels) if int(label) == cluster]
        output[str(cluster)] = {'size': len(members), 'fields': {k: {'counts': dict(collections.Counter(normalize(r[k]) or '<MISSING>' for r in members)), 'meaning': dictionary[k]['label'], 'codes': dictionary[k]['codes']} for k in ORDER if k not in ('ID', 'BARANGAY', 'OTHER')}}
    return output


def dump_bundle(path, bundle):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_suffix('.tmp')
    with temp.open('wb') as f:
        pickle.dump(bundle, f)
    os.replace(temp, path)


def plot_comparison(results, out):
    cache = ROOT / 'data/matplotlib'
    cache.mkdir(parents=True, exist_ok=True)
    os.environ.setdefault('MPLCONFIGDIR', str(cache))
    import matplotlib
    matplotlib.use('Agg')
    from matplotlib import pyplot as plt
    fig, axes = plt.subplots(2, 2, figsize=(10, 7), constrained_layout=True)
    for i, algorithm in enumerate(('kmeans', 'kmodes')):
        for j, weighted in enumerate((False, True)):
            entries = [r for r in results if r['algorithm'] == algorithm and r['weighted'] == weighted]
            axis = axes[i, j]
            axis.plot([r['k'] for r in entries], [r['cost'] for r in entries], marker='o')
            axis.set(title=algorithm.upper() + (' / group weighted' if weighted else ' / unweighted'), xlabel='Number of clusters (K)', ylabel='Within-model cost')
            axis.grid(alpha=.25)
    fig.suptitle('Elbow curves: compare costs only within each panel')
    fig.savefig(Path(out) / 'elbow.png', dpi=180)
    plt.close(fig)


def compare(args):
    import numpy as np
    from sklearn.metrics import adjusted_rand_score
    c = load_json(args.confirmation)
    rows = prepared_rows(args.data, c)
    review = load_json(args.data_review)
    if not all(review.get(k) for k in ('reviewed_by', 'reviewed_on', 'duplicate_profile_resolution', 'invalid_code_resolution', 'provenance_assessment')) or review.get('data_sha256') != hashlib.sha256(Path(args.data).read_bytes()).hexdigest():
        raise ValueError('Data review must document duplicate profiles, invalid codes, provenance and the exact working CSV hash.')
    datetime.date.fromisoformat(review['reviewed_on'])
    train, holdout = group_split(rows)
    development = [rows[i] for i in train]
    prep = fit_preprocessor(development)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    save_json(out / 'split.json', {'seed': SEED, 'development_ids': [rows[i]['ID'] for i in train], 'holdout_ids': [rows[i]['ID'] for i in holdout]})
    write_csv(out / 'holdout_blind_review.csv', [{**{'ID': rows[i]['ID']}, **{k: rows[i][k] for k in ORDER if k != 'ID'}} for i in holdout])
    results = []
    for algorithm in ('kmeans', 'kmodes'):
        for weighted in (False, True):
            x = matrix(development, prep, algorithm, weighted, c)
            distinct = len({tuple(v) for v in x})
            for k in range(2, min(10, distinct, len(x) - 1) + 1):
                started = time.perf_counter()
                model = make_model(algorithm, k, weighted, SEED)
                labels = model.fit_predict(x)
                rng = np.random.RandomState(SEED)
                sample = rng.choice(len(x), min(500, len(x)), replace=False)
                stability, resamples = [], []
                for seed in (7, 21, 84):
                    other = make_model(algorithm, k, weighted, seed).fit(x)
                    stability.append(float(adjusted_rand_score(labels, other.predict(x))))
                    subset = np.random.RandomState(seed).choice(len(x), max(k + 1, int(.8 * len(x))), replace=False)
                    other = make_model(algorithm, k, weighted, seed).fit(x[subset])
                    resamples.append(float(adjusted_rand_score(labels, other.predict(x))))
                name = f'{algorithm}-k{k}-' + ('weighted' if weighted else 'unweighted')
                result = {'candidate': name, 'algorithm': algorithm, 'k': k, 'weighted': weighted, 'silhouette': silhouette(x[sample], labels[sample], algorithm, weighted), 'seed_stability_ari': sum(stability)/len(stability), 'resample_stability_ari': sum(resamples)/len(resamples), 'cost': float(model.inertia_ if algorithm == 'kmeans' else model.cost_), 'cluster_sizes': dict(collections.Counter(str(v) for v in labels)), 'seconds': time.perf_counter() - started}
                results.append(result)
                bundle = {'version': name, 'stage': 'development', 'algorithm': algorithm, 'weighted': weighted, 'k': k, 'preprocessing': prep, 'confirmation': c, 'model': model, 'profiles': profiles(development, labels, c), 'development_ids': [rows[i]['ID'] for i in train], 'holdout_ids': [rows[i]['ID'] for i in holdout], 'data_sha256': file_hash(args.data)}
                dump_bundle(out / (name + '.pkl'), bundle)
                save_json(out / (name + '-profiles.json'), bundle['profiles'])
                print(json.dumps(result), flush=True)
    save_json(out / 'comparison.json', results)
    save_json(out / 'preprocessing.json', prep)
    save_json(out / 'experiment.json', {'source_sha256': file_hash(args.data), 'seed': SEED, 'stability_seeds': [7, 21, 84], 'resample_fraction': .8, 'resampling_unit': 'farmer records within development', 'silhouette_sample_max': 500, 'kmeans_n_init': 10, 'kmodes_init': 'Huang', 'kmodes_n_init': 5, 'feature_groups': FEATURE_GROUPS, 'features': FEATURES, 'data_review': review, 'note': 'Candidate development experiments; no automatic selection or holdout evaluation.'})
    plot_comparison(results, out)
    (out / 'comparison.md').write_text('# Clustering comparison\n\nNo candidate is automatically approved. Costs and silhouettes use different geometries across algorithms and must not be ranked as interchangeable. Review stability, small clusters and PVET interpretability.\n\n|Candidate|Silhouette|Seed ARI|Resample ARI|Sizes|\n|---|---:|---:|---:|---|\n' + '\n'.join(f"|{r['candidate']}|{r['silhouette']}|{r['seed_stability_ari']:.3f}|{r['resample_stability_ari']:.3f}|{r['cluster_sizes']}|" for r in results), encoding='utf-8')


def load_bundle(path):
    # Only load locally generated, access-protected artifacts. Pickle is not an upload format.
    with Path(path).open('rb') as f:
        return pickle.load(f)


def select(args):
    selection = load_json(args.selection)
    scope = selection.get('scope', 'domain_reviewed')
    if scope not in ('research', 'domain_reviewed'):
        raise ValueError('Selection scope must be research or domain_reviewed.')
    required = ('candidate', 'reviewed_by', 'reviewed_on', 'rationale')
    if scope == 'domain_reviewed':
        required += ('pvet_interpretation',)
    if not all(selection.get(k) for k in required):
        raise ValueError('Named model review, rationale and PVET interpretation are required.')
    datetime.date.fromisoformat(selection['reviewed_on'])
    name = selection['candidate']
    if not name.replace('-', '').isalnum():
        raise ValueError('Invalid candidate name.')
    bundle = load_bundle(Path(args.candidates) / (name + '.pkl'))
    if bundle['stage'] != 'development':
        raise ValueError('Select only a development candidate.')
    if scope == 'domain_reviewed' and bundle['algorithm'] != 'kmeans' and not selection.get('adviser_methodology_approved_by'):
        raise ValueError('Alternative algorithm requires named adviser methodology approval.')
    bundle['selection'] = selection
    bundle['stage'] = 'frozen_research' if scope == 'research' else 'frozen'
    dump_bundle(args.out, bundle)
    save_json(Path(args.out).with_suffix('.json'), {k: v for k, v in bundle.items() if k != 'model'})


def evaluate_holdout(args):
    import numpy as np
    bundle = load_bundle(args.model)
    if bundle['stage'] not in ('frozen', 'frozen_research'):
        raise ValueError('Freeze model selection before evaluating holdout.')
    if hashlib.sha256(Path(args.data).read_bytes()).hexdigest() != bundle['data_sha256']:
        raise ValueError('Training data changed after comparison. Repeat comparison.')
    rows = [r for r in read_csv(args.data) if r['ID'] in set(bundle['holdout_ids'])]
    if len(rows) != len(bundle['holdout_ids']) or {r['ID'] for r in rows} != set(bundle['holdout_ids']):
        raise ValueError('Holdout IDs are missing or repeated.')
    x = matrix(rows, bundle['preprocessing'], bundle['algorithm'], bundle['weighted'], bundle['confirmation'])
    labels = bundle['model'].predict(x)
    for key in FEATURES:
        if any(normalize(r[key]) is None for r in rows):
            raise ValueError('Holdout contains missing inputs; resolve separately before web-readiness evaluation.')
    out = Path(args.out)
    save_json(out / 'holdout.json', {'stage': bundle['stage'], 'domain_review_completed': bundle['stage'] == 'frozen', 'rows': len(rows), 'holdout_ids': [r['ID'] for r in rows], 'data_sha256': bundle['data_sha256'], 'model_sha256': file_hash(args.model), 'silhouette': silhouette(x[:500], labels[:500], bundle['algorithm'], bundle['weighted']), 'cluster_sizes': dict(collections.Counter(str(v) for v in labels)), 'profiles': profiles(rows, labels, bundle['confirmation']), 'model_version': bundle['version'], 'note': 'Holdout results are descriptive, not supervised accuracy. Research-only freezes do not authorize service evaluation or production release.'})
    write_csv(out / 'holdout_assignments.csv', [{'ID': r['ID'], 'cluster_id': int(label)} for r, label in zip(rows, labels)], ['ID', 'cluster_id'])


def frozen_recommendations(args):
    bundle = load_bundle(args.model)
    rules = load_json(args.rules)
    validate_rules(rules)
    if bundle['stage'] != 'frozen' or rules.get('model_version') != bundle['version']:
        raise ValueError('Evaluation rules must reference the frozen model.')
    if any(set(rule['clusters']) - {int(k) for k in bundle['profiles']} for rule in rules['services']):
        raise ValueError('Evaluation rule refers to a cluster absent from the frozen model.')
    if file_hash(args.data) != bundle['data_sha256']:
        raise ValueError('Evaluation data differs from the frozen data.')
    rows = [r for r in read_csv(args.data) if r['ID'] in set(bundle['holdout_ids'])]
    if {r['ID'] for r in rows} != set(bundle['holdout_ids']):
        raise ValueError('Holdout records are missing from the data.')
    checks = load_json(args.checks)
    if set(checks) != set(bundle['holdout_ids']) or any(not isinstance(v, dict) or any(type(b) is not bool for b in v.values()) for v in checks.values()):
        raise ValueError('Provide a Boolean check object for every frozen holdout ID.')
    for row in rows:
        errors = validate_record(row, confirmation=bundle['confirmation'])
        if errors:
            raise ValueError('; '.join(errors))
    x = matrix(rows, bundle['preprocessing'], bundle['algorithm'], bundle['weighted'], bundle['confirmation'])
    clusters = bundle['model'].predict(x)
    results, details = [], []
    for row, cluster in zip(rows, clusters):
        result = recommend(row, int(cluster), rules, checks[row['ID']], unseen_categories(row, bundle['preprocessing']))
        selected = {r['service'] for r in result['suggested_services']}
        results.append({'ID': row['ID'], **{s: int(s in selected) for s in SERVICES}})
        details.append({'ID': row['ID'], 'cluster_id': int(cluster), **result})
    write_csv(args.out, results, ['ID', *SERVICES])
    save_json(Path(args.out).with_suffix('.json'), {'stage': 'frozen', 'model_version': bundle['version'], 'model_sha256': file_hash(args.model), 'rules_sha256': file_hash(args.rules), 'data_sha256': bundle['data_sha256'], 'checks_sha256': file_hash(args.checks), 'predictions_sha256': file_hash(args.out), 'holdout_ids': bundle['holdout_ids'], 'details': details})


def release(args):
    bundle = load_bundle(args.model)
    rules = load_json(args.rules)
    approval = load_json(args.approval)
    validate_rules(rules)
    if bundle['stage'] != 'frozen' or rules.get('model_version') != bundle['version']:
        raise ValueError('Rules must reference the frozen model being released.')
    if not all(approval.get(k) for k in ('approved_by', 'approved_on', 'holdout_review', 'recommendation_validation')):
        raise ValueError('Release requires real holdout and recommendation validation records.')
    datetime.date.fromisoformat(approval['approved_on'])
    try:
        holdout = load_json(approval['holdout_review'])
        evaluation = load_json(approval['recommendation_validation'])
    except (OSError, ValueError) as e:
        raise ValueError('Release requires matching evaluation artifacts, not descriptive approval text.') from e
    for report in (holdout, evaluation):
        if report.get('stage') != 'frozen' or report.get('model_version') != bundle['version'] or report.get('model_sha256') != file_hash(args.model) or report.get('data_sha256') != bundle['data_sha256'] or set(report.get('holdout_ids', [])) != set(bundle['holdout_ids']):
            raise ValueError('Release evaluation artifacts do not match this frozen model/data/holdout.')
    if evaluation.get('rules_sha256') != file_hash(args.rules) or not evaluation.get('independent_review', {}).get('blind_review') or not evaluation['independent_review'].get('reviewed_by'):
        raise ValueError('Recommendation evaluation requires matching approved rules and independent blind PVET review.')
    if hashlib.sha256(Path(args.data).read_bytes()).hexdigest() != bundle['data_sha256']:
        raise ValueError('Data changed after model evaluation.')
    rows = prepared_rows(args.data, bundle['confirmation'])
    prep = fit_preprocessor(rows)
    x = matrix(rows, prep, bundle['algorithm'], bundle['weighted'], bundle['confirmation'])
    model = make_model(bundle['algorithm'], bundle['k'], bundle['weighted'], SEED)
    labels = model.fit_predict(x)
    bundle.update(stage='refit_pending_review', version=args.version, preprocessing=prep, model=model, profiles=profiles(rows, labels, bundle['confirmation']), release_approval=approval)
    dump_bundle(args.out, bundle)
    save_json(Path(args.out).with_suffix('.json'), {k: v for k, v in bundle.items() if k != 'model'})
    print('Refit package created. Cluster IDs may have changed: review new profiles and remap approved rules before activation.')


def activate(args):
    bundle = load_bundle(args.model)
    rules = load_json(args.rules)
    validate_rules(rules)
    if bundle['stage'] != 'refit_pending_review' or rules.get('model_version') != bundle['version'] or not rules.get('refit_profiles_reviewed_by'):
        raise ValueError('Activation requires PVET-reviewed refit profiles and rules bound to this model version.')
    clusters = set(int(k) for k in bundle['profiles'])
    if any(set(r['clusters']) - clusters for r in rules['services']):
        raise ValueError('Rule references a cluster absent from the release model.')
    bundle.update(stage='active', rules=rules)
    dump_bundle(args.out, bundle)
    save_json(Path(args.out).with_suffix('.json'), {**{k: v for k, v in bundle.items() if k != 'model'}, 'artifact_sha256': file_hash(args.out)})


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    p = sub.add_parser('audit'); p.add_argument('source'); p.add_argument('--out', default='reports/audit'); p.add_argument('--confirmation', default='config/confirmation.json')
    p = sub.add_parser('compare'); p.add_argument('--data', default='reports/audit/working.csv'); p.add_argument('--confirmation', default='config/confirmation.json'); p.add_argument('--data-review', default='config/data_review.json'); p.add_argument('--out', default='models/candidates')
    p = sub.add_parser('select'); p.add_argument('--selection', required=True); p.add_argument('--candidates', default='models/candidates'); p.add_argument('--out', default='models/frozen.pkl')
    p = sub.add_parser('holdout'); p.add_argument('--model', default='models/frozen.pkl'); p.add_argument('--data', default='reports/audit/working.csv'); p.add_argument('--out', default='reports/holdout')
    p = sub.add_parser('recommendations'); p.add_argument('--model', default='models/frozen.pkl'); p.add_argument('--data', default='reports/audit/working.csv'); p.add_argument('--rules', default='config/rules.json'); p.add_argument('--checks', required=True); p.add_argument('--out', default='reports/frozen_predictions.csv')
    p = sub.add_parser('release'); p.add_argument('--model', default='models/frozen.pkl'); p.add_argument('--data', default='reports/audit/working.csv'); p.add_argument('--rules', default='config/rules.json'); p.add_argument('--approval', required=True); p.add_argument('--version', required=True); p.add_argument('--out', default='models/refit.pkl')
    p = sub.add_parser('activate'); p.add_argument('--model', default='models/refit.pkl'); p.add_argument('--rules', default='config/rules.json'); p.add_argument('--out', default='models/active.pkl')
    args = parser.parse_args()
    try:
        if args.command == 'audit':
            print(json.dumps(audit(args.source, args.out, load_json(args.confirmation)), ensure_ascii=False))
        else:
            {'compare': compare, 'select': select, 'holdout': evaluate_holdout, 'recommendations': frozen_recommendations, 'release': release, 'activate': activate}[args.command](args)
    except (ValueError, OSError, ImportError, KeyError) as e:
        print(str(e), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
