<?php

/*
 * This file is part of Tuli, a static analyzer for PHP
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

$code = <<<'EOF'
<?php

function foo(int $a) : int {
	if ($a > 0 && $a < 100) {
		return $a;
	}
	return false;
}
?>
EOF;

return [
    $code,
    [
        [
            "line"    => 7,
            "message" => "Type mismatch on return value, found bool expecting int",
        ],
    ],
];