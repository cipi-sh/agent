<?php

namespace Cipi\Agent\Anonymizer\Dialect;

use Cipi\Agent\Anonymizer\Stats;

interface DumpProcessor
{
    /**
     * Stream $inputPath to $outputPath, transforming the configured columns
     * and dropping the rows of truncated tables.
     */
    public function process(string $inputPath, string $outputPath): Stats;
}
