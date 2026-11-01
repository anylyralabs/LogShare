<?php

declare(strict_types=1);

return \App\Config::GetPath('mongo.compactionEnabled', true) === true
    ? [\App\System\MongoCompactProcess::class]
    : [];
