<?php

declare(strict_types=1);

// Stands in for a DNS server that never answers in time: Core's process
// resolver runs this instead of its lookup script. It answers with a public
// address, but only after every site monitor deadline has passed.
time_nanosleep(20, 0);

echo json_encode(['93.184.216.34']);
