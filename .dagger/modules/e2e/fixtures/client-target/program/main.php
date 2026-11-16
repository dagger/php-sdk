<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dagger\Clients\FileLister\Client as FileLister;
use Dagger\Clients\FileMaker\Client as FileMaker;

echo FileLister::fileLister()->entries(FileMaker::fileMaker()->writeHtml(['a', 'b'])), PHP_EOL;
