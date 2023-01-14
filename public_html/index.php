<?php
declare(strict_types=1);
error_reporting(-1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode(main(
    +($_REQUEST['timestamp'] ?? '0'),
    isset($_REQUEST['usernames']) ? explode('|', $_REQUEST['usernames']) : []
));

function main(int $timestamp, array $rawUsernames): array {
    if ($timestamp === 0 || count($rawUsernames) === 0) {
        return ['#documentation' => 'Checks users eligibility, use it like ?usernames=Salgo60|Fabian_Roudra_Baroi&timestamp=1673719206937 Source: github.com/ebraminio/user-eligibility'];
    }

    $ini = parse_ini_file('../replica.my.cnf');
    $db = mysqli_connect('fawiki.analytics.db.svc.eqiad.wmflabs', $ini['user'], $ini['password'], 'fawiki_p');

    $usernames = [];
    foreach ($rawUsernames as $u) {
        $usernames[] = mysqli_real_escape_string($db, $u);
    }

    $actors = fetch_query($db, "
SELECT actor_name, actor_id
FROM actor
WHERE actor_name IN ('" . implode("', '", $usernames) . "')
");
    $actorIds = array_values($actors);
    $firstEdits = fetch_query($db, "
SELECT rev_actor, UNIX_TIMESTAMP(MIN(rev_timestamp)) * 1000
FROM revision_userindex
WHERE rev_actor IN (" . implode(", ", $actorIds) . ")
GROUP BY rev_actor
");
    $sixMonthsEdits = fetch_query($db, "
SELECT rev_actor, COUNT(*)
FROM revision_userindex JOIN page ON page_id = rev_page AND page_namespace = 0
WHERE rev_timestamp > DATE_SUB(FROM_UNIXTIME($timestamp / 1000), INTERVAL 6 MONTH)
  AND rev_timestamp < FROM_UNIXTIME($timestamp / 1000)
  AND rev_actor IN (" . implode(", ", $actorIds) . ")
GROUP BY rev_actor
");

    $result = [];
    foreach ($actors as $user => $id) {
        $result[$user] = ['firstEdit' => +$firstEdits[$id], 'sixMonthsEdits' => +$sixMonthsEdits[$id]];
    }

    return $result;
}

function fetch_query(mysqli $db, string $query) {
    $q = mysqli_query($db, $query);
    $result = [];
    while ($row = $q->fetch_row()) $result[$row[0]] = $row[1];
    mysqli_free_result($q);
    return $result;
}
