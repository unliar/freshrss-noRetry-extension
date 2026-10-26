<?php
return [
	'noretry' => [
		'whitelist' => 'Domain whitelist (never back off)',
		'whitelist_help' => 'One domain per line (commas or semicolons also work). These domains will never trigger Retry-After, and will not block other feeds on the same domain.',
		'effective' => 'Currently active',
		'empty' => '(empty)',
	],
];
