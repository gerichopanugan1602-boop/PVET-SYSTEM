<?php
declare(strict_types=1);

/**
 * Recommendation shown after the model assigns a cluster (0-4).
 * Each cluster has a list of paragraphs. Edit the text here freely.
 */
function cluster_info(int $cluster): ?array
{
    $info = [
        0 => ['paragraphs' => [
            'The office is providing disinfectants to help farmers maintain cleanliness and sanitation in their pig farms.',
            'This assistance is particularly relevant to Cluster 0, where most farmers use open housing and many reported having no awareness of ASF.',
            'And additionally DA-LGU ASF checkpoint monitoring to support ASF prevention and farm biosecurity.',
        ]],
        1 => ['paragraphs' => [
            'Farm management and feed related advice on proper feed management, feed selection, storage, and cost-efficient feeding practices to help farmers manage their production expenses.',
        ]],
        2 => ['paragraphs' => [
            'Biosecurity and farm sanitation advice. The office may provide basic advice on proper use of footbaths and disinfectants, regular cleaning of pig pens and farm equipment, and limiting unnecessary farm visitors.',
            'Since many farmers already use footbaths and reported no annual mortality, the office may encourage them to maintain these practices. The DA-LGU may also continue checkpoint monitoring and the provision of disinfectants to support ASF prevention.',
        ]],
        3 => ['paragraphs' => [
            'The DA-LGU provide disinfectants and give basic advice on their proper use for cleaning and disinfecting pig pens and other farm areas, and to maintain regular sanitation practices to help reduce the risk of disease.',
        ]],
        4 => ['paragraphs' => [
            'Disinfectant provision and biosecurity advice.',
            'The DA-LGU may provide disinfectants and give basic advice on proper cleaning and disinfection of pig farms. Since all farmers reported distance as a barrier and most rarely consult veterinarians, providing disinfectants can give them practical support for maintaining farm sanitation and biosecurity.',
        ]],
    ];

    return $info[$cluster] ?? null;
}