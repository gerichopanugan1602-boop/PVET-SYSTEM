<?php
declare(strict_types=1);

/**
 * Pigerry - cluster assignment using the frozen research model (ml/frozen.pkl) through Python.
 * Needs Python 3 + the packages in ml/requirements.txt on the machine running the system.
 */

// On XAMPP/Windows a FULL path is safest (Apache may not see your PATH),
// e.g. 'C:\\Python310\\python.exe'. On Linux usually 'python3'.
const PYTHON_CMD = 'C:\\Users\\ASUS\\pigerry-venv\\Scripts\\python.exe';

/**
 * @param array $a the 35 model inputs from model_input() (model field name => code; checkbox 1/0)
 * @return array ['ok'=>true,'cluster'=>int, ...] or ['ok'=>false,'errors'=>[...]]
 */
function predictCluster(array $a): array
{
    $cmd  = [PYTHON_CMD, __DIR__ . DIRECTORY_SEPARATOR . 'predict.py', 'demo'];
    $proc = @proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) {
        return ['ok' => false, 'errors' => ['Could not start Python. Check PYTHON_CMD in ml/cluster.php.']];
    }

    fwrite($pipes[0], json_encode(['record' => $a], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $res = json_decode((string) $out, true);
    if (!is_array($res)) {
        return ['ok' => false, 'errors' => ['Python error: ' . trim((string) $err)]];
    }
    if (empty($res['ok']) || !isset($res['cluster_id'])) {
        return ['ok' => false, 'errors' => [(string) ($res['error'] ?? 'Unknown model error')]];
    }

    $res['cluster'] = (int) $res['cluster_id']; // keeps the key assessment.php already uses
    return $res;
}

/**
 * Recommendations for a cluster, read from ml/demo-suggestions.json
 * (the same file the model bridge uses, so there is one source of truth).
 *
 * @return array|null ['items'=>[['label','kind','description'],...], 'notice'=>string, 'version'=>string]
 */
function pvet_recommendations(int $cluster): ?array
{
    static $cfg = null;
    if ($cfg === null) {
        $raw = @file_get_contents(__DIR__ . '/demo-suggestions.json');
        $cfg = ($raw === false) ? [] : (json_decode(ltrim($raw, "\xEF\xBB\xBF"), true) ?: []);
    }

    $text = $cfg['clusters'][(string) $cluster] ?? null;
    if (!is_string($text) || trim($text) === '') {
        return null;
    }

    $items = [];
    foreach (preg_split('/\R/', $text) as $label) {
        $label = trim($label);
        if ($label === '') {
            continue;
        }
        $items[] = [
            'label'       => $label,
            'kind'        => (string) ($cfg['recommendation_kinds'][$label] ?? ''),
            'description' => (string) ($cfg['resource_descriptions'][$label] ?? ''),
        ];
    }

    return [
        'items'   => $items,
        'notice'  => (string) ($cfg['notice'] ?? ''),
        'version' => (string) ($cfg['version'] ?? ''),
    ];
}