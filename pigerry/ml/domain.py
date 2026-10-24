"""Dictionary-backed validation shared by auditing, training and web inference."""
import datetime
import math
import re

CATEGORIES = {
    'AGE': ('Age range', ['17 and below', '18–25', '26–35', '36–45', '46–55', '56 and above']),
    'SEX': ('Sex', ['Male', 'Female']),
    'CIVIL STATUS': ('Civil status', ['Single', 'Married', 'Widowed', 'Separated']),
    'MUNICIPALITY': ('Municipality', ['Cabarroguis', 'Diffun', 'Maddela', 'Nagtipunan', 'Aglipay', 'Saguday']),
    'EDUC': ('Highest education', ['No formal education', 'Elementary level', 'Elementary graduate', 'High school level', 'High school graduate', 'College level', 'College graduate']),
    'EMPLOY': ('Employment', ['Employed', 'Self-employed', 'Unemployed', 'Student']),
    'INCOME': ('Monthly household income', ['Below PHP 10,000', 'PHP 10,000–20,000', 'PHP 20,001–30,000', 'PHP 30,001 and above']),
    'FARM CLASS': ('Farm classification', ['Backyard', 'Commercial']),
    'NUMSWINE': ('Number of swine range', ['1–10 pigs', '11–20 pigs', '21–50 pigs', '51 and above']),
    'YEARSEXP': ('Swine farming experience', ['Below 1 year', '1–3 years', '4–7 years', '8 years and above']),
    'FEEDPRAC': ('Feeding practice', ['Traditional feeding', 'Commercial feeds', 'Mixed feeding']),
    'FEEDSRC': ('Feed source', ['Homemade feeds', 'Agricultural supply store', 'Commercial feed brand dealership']),
    'HOUSING': ('Housing system', ['Open', 'Semi-closed', 'Closed']),
    'WATERSRC': ('Water source', ['Deep well', 'Faucet', 'River/Creek']),
    'EXPENSES': ('Monthly farm expense range', ['Below PHP 5,000', 'PHP 5,000–10,000', 'PHP 10,001–20,000', 'PHP 20,001 and above']),
    'MORTALITY': ('Annual mortality range', ['None', '1–5', '6–10', 'More than 10']),
    'ASF_AWARE': ('Aware of ASF', ['No', 'Yes']),
    'ASF_OUTBREAK': ('Experienced ASF outbreak', ['No', 'Yes']),
    'VET_CONSULT': ('Consults veterinarian when pigs are sick', ['Always', 'Sometimes', 'Rarely', 'Never']),
    'DISINFECT_FREQ': ('Disinfection frequency', ['Daily', 'Weekly', 'Monthly', 'Rarely', 'Never']),
    'VACCINATED': ('Pigs received vaccinations', ['No', 'Yes']),
    'VET_ACCESS': ('Veterinary access', ['Very accessible', 'Accessible', 'Moderately accessible', 'Difficult to access', 'Not accessible']),
    'PREF_LOC': ('Preferred service location', ['Within barangay', 'Within municipality', 'Anywhere in Quirino', 'Online/Remote']),
    'SATISFACTION': ('Service satisfaction', ['Very satisfied', 'Satisfied', 'Neutral', 'Dissatisfied', 'Very dissatisfied']),
    'USE_SYSTEM': ('Willing to use recommendation system', ['No', 'Yes']),
}
CHECKBOX_GROUPS = {
    'disease': dict(DIS_ASF='African Swine Fever', DIS_HOGCHOL='Hog Cholera', DIS_PNEUMO='Pneumonia', DIS_DIARR='Diarrhea', DIS_SKIN='Skin disease', DIS_NONE='No diseases'),
    'symptoms': dict(SYM_FEVER='High fever', SYM_APPETITE='Loss of appetite', SYM_SKIN='Skin discoloration', SYM_SUDDEN='Sudden death', SYM_WEAK='Weakness', SYM_DOTKNOW='Does not know symptoms'),
    'biosecurity': dict(BIO_CLEAN='Cleaning/disinfection', BIO_FOOTHBATH='Foot bath', BIO_ISOLATION='Isolation of sick pigs', BIO_VISITORS='Controlled visitors', BIO_WASTE='Proper waste disposal', BIO_NONE='No biosecurity measures'),
    'information': dict(INFO_MAO='Municipal Agriculture Office', INFO_OPVET='Provincial Veterinarian', INFO_DABAI='DA-BAI', INFO_PRIVVET='Private veterinarian', INFO_SOCMED='Social media', INFO_FARMERS='Fellow farmers', INFO_WEB='Internet websites'),
    'providers': dict(ORG_LGU='LGU veterinary/agriculture office', ORG_OPVET='Provincial Veterinarian', ORG_DA='Department of Agriculture', ORG_NGO='NGO', ORG_PRIVCLINIC='Private clinic', ORG_FEEDSUP='Feed supplier/company'),
    'needs': dict(NEED_VETCONSULT='Veterinary consultation', NEED_ASFPREV='ASF prevention assistance', NEED_VACC='Vaccination', NEED_DIAG='Disease diagnosis', NEED_SANIT='Sanitation assistance', NEED_FEEDNUTR='Feeding/nutrition consultation', NEED_MEDREC='Medicine recommendations', NEED_EMERG='Emergency treatment', NEED_TRAINING='Training/seminars', NEED_FINANCE='Financial/livelihood assistance'),
    'barriers': dict(CH_LACKINFO='Lack of information', CH_TRANSPORT='Transportation difficulty', CH_FINANCE='Financial limitations', CH_LACKVET='Lack of veterinarians', CH_LACKMED='Lack of medicines', CH_INTERNET='Poor internet', CH_DISTANCE='Physical distance'),
}
FEATURE_GROUPS = {
    'farm': ['FARM CLASS', 'NUMSWINE', 'YEARSEXP', 'FEEDPRAC', 'FEEDSRC', 'HOUSING', 'WATERSRC'],
    'finance': ['INCOME', 'EXPENSES'],
    'disease': ['MORTALITY', 'ASF_AWARE', 'ASF_OUTBREAK', *CHECKBOX_GROUPS['disease']],
    'biosecurity': ['DISINFECT_FREQ', *CHECKBOX_GROUPS['biosecurity']],
    'access': ['VET_CONSULT', 'VACCINATED', 'VET_ACCESS', *CHECKBOX_GROUPS['barriers']],
}
FEATURES = [key for group in FEATURE_GROUPS.values() for key in group]
CHECKBOXES = {k for g in CHECKBOX_GROUPS.values() for k in g}
ORDER = ['ID', 'AGE', 'SEX', 'CIVIL STATUS', 'MUNICIPALITY', 'BARANGAY', 'EDUC', 'EMPLOY', 'INCOME', 'OTHER', 'FARM CLASS', 'NUMSWINE', 'YEARSEXP', 'FEEDPRAC', 'FEEDSRC', 'HOUSING', 'WATERSRC', 'EXPENSES', 'MORTALITY', *CHECKBOX_GROUPS['disease'], 'ASF_AWARE', 'ASF_OUTBREAK', *CHECKBOX_GROUPS['symptoms'], *CHECKBOX_GROUPS['biosecurity'], 'VET_CONSULT', 'DISINFECT_FREQ', 'VACCINATED', *CHECKBOX_GROUPS['information'], *CHECKBOX_GROUPS['providers'], *CHECKBOX_GROUPS['needs'], 'VET_ACCESS', 'PREF_LOC', *CHECKBOX_GROUPS['barriers'], 'SATISFACTION', 'USE_SYSTEM']
FIELDS = {}
for key in ORDER:
    if key in CATEGORIES:
        label, choices = CATEGORIES[key]
        field = {'label': label, 'type': 'category', 'codes': {str(i): v for i, v in enumerate(choices)}}
    elif key in CHECKBOXES:
        label = next(g[key] for g in CHECKBOX_GROUPS.values() if key in g)
        field = {'label': label, 'type': 'checkbox', 'codes': {'0': 'Unconfirmed meaning', '1': 'Unconfirmed meaning'}}
    else:
        field = {'label': {'ID': 'Farmer identifier', 'BARANGAY': 'Barangay', 'OTHER': 'Other income/work'}[key], 'type': 'identifier' if key == 'ID' else 'text', 'codes': {}}
    field.update(missing_policy='Preserve unknown; reject missing clustering inputs at prediction. Training modes are learned only on development data.', use='clustering' if key in FEATURES else 'identifier' if key == 'ID' else 'profile/evaluation', source='VALUES (1).docx' if key != 'ID' else 'Workbook header; identifier provenance needs PVET confirmation')
    FIELDS[key] = field

SERVICES = ['vitamins', 'vaccination', 'treatment_support', 'castration', 'financial_aid', 'starting_piglets']


def categorical_distance(a, b, **kwargs):
    """Stable module path for serialized K-Modes models created by the CLI."""
    import numpy as np
    weights = kwargs.get('weights')
    return np.sum((a != b) * (weights if weights is not None else 1), axis=1)


def normalize(value):
    if value is None or value == '':
        return None
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return str(int(value)) if math.isfinite(value) and value == int(value) else str(value)
    return str(value).strip() or None


def require_checkbox_encoding(c):
    if type(c.get('checkbox_selected')) is not int or type(c.get('checkbox_unselected')) is not int or {c['checkbox_selected'], c['checkbox_unselected']} != {0, 1}:
        raise ValueError('Checkbox encoding has not been confirmed.')


def require_confirmation(c):
    require_checkbox_encoding(c)
    if c.get('one_row_per_farmer') is not True or not all(c.get(k) for k in ('confirmed_by', 'confirmed_on', 'missing_meaning', 'prior_processing')):
        raise ValueError('PVET must confirm row identity, missing meanings and prior processing with a named reviewer and date.')
    datetime.date.fromisoformat(c['confirmed_on'])


def validate_record(record, partial=False, confirmation=None):
    errors = []
    for key, value in record.items():
        if key not in FIELDS:
            errors.append(f'Unknown field: {key}')
            continue
        if isinstance(value, (dict, list, bool)):
            errors.append(f'{key}: expected a scalar survey value')
            continue
        value = normalize(value)
        if value is not None and FIELDS[key]['codes'] and (isinstance(value, bool) or value not in FIELDS[key]['codes']):
            errors.append(f'{key}: invalid category code {value!r}')
        if value is not None and (isinstance(value, (dict, list, bool)) or len(str(value)) > 250):
            errors.append(f'{key}: invalid or overlong value')
    if not partial:
        errors.extend(f'{key}: required' for key in FEATURES if normalize(record.get(key)) is None)
    if confirmation is not None:
        require_checkbox_encoding(confirmation)
        selected = str(confirmation['checkbox_selected'])
        for group, none in [('disease', 'DIS_NONE'), ('biosecurity', 'BIO_NONE'), ('symptoms', 'SYM_DOTKNOW')]:
            if normalize(record.get(none)) == selected and any(normalize(record.get(k)) == selected for k in CHECKBOX_GROUPS[group] if k != none):
                errors.append(f'{group}: mutually exclusive selections')
    return errors


def validate_rules(rules):
    if rules.get('approved') is not True or not rules.get('approved_by') or not rules.get('approved_on') or not rules.get('version'):
        raise ValueError('Service rules need named PVET approval, date and version.')
    datetime.date.fromisoformat(rules['approved_on'])
    if not isinstance(rules.get('services'), list) or not rules['services']:
        raise ValueError('An approved service rule set must contain reviewed criteria.')
    seen = set()
    for rule in rules.get('services', []):
        service = rule.get('service')
        if service not in SERVICES or service in seen:
            raise ValueError('Invalid or duplicate service rule.')
        seen.add(service)
        if not rule.get('reason') or not rule.get('required_checks') or not isinstance(rule.get('clusters'), list):
            raise ValueError(f'{service}: reason, cluster list and individual checks are required.')
        if not rule['clusters'] or any(type(c) is not int or c < 0 for c in rule['clusters']) or not isinstance(rule['required_checks'], list) or any(not isinstance(key, str) or not re.fullmatch(r'[a-z][a-z0-9_]{0,63}', key) for key in rule['required_checks']):
            raise ValueError(f'{service}: use nonnegative integer cluster IDs and valid individual check names.')
        for section in ('conditions', 'exclusions'):
            for key, codes in rule.get(section, {}).items():
                if key not in FIELDS or not isinstance(codes, list) or not codes or any(str(v) not in FIELDS[key]['codes'] for v in codes):
                    raise ValueError(f'{service}: invalid {section}.')


def recommend(record, cluster, rules, checks, unseen=None):
    result = {'suggested_services': [], 'assessment_flags': []}
    if unseen:
        result['assessment_flags'].append('PVET assessment required; valid categories not represented in training: ' + ', '.join(unseen))
        return result
    try:
        validate_rules(rules)
    except ValueError:
        result['assessment_flags'].append('PVET assessment required')
        return result
    for rule in rules['services']:
        if cluster not in rule['clusters']:
            continue
        conditions = rule.get('conditions', {})
        exclusions = rule.get('exclusions', {})
        if any(normalize(record.get(k)) is None for k in [*conditions, *exclusions]):
            result['assessment_flags'].append(f"{rule['service']}: PVET assessment required; missing eligibility data")
            continue
        if not all(normalize(record.get(k)) in [str(v) for v in values] for k, values in conditions.items()):
            continue
        if any(normalize(record.get(k)) in [str(v) for v in values] for k, values in exclusions.items()):
            continue
        missing = [key for key in rule['required_checks'] if checks.get(key) is not True]
        if missing:
            result['assessment_flags'].append(f"{rule['service']}: PVET assessment required; checks: {', '.join(missing)}")
        else:
            result['suggested_services'].append({'service': rule['service'], 'reason': rule['reason']})
    if not result['suggested_services'] and not result['assessment_flags']:
        result['assessment_flags'].append('PVET assessment required; no approved rule matched')
    return result
