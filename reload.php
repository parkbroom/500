<?php

while(true) {
	shell_exec("php index.php");
	echo "died but restarted";
	echo "\n";
	sleep(10);
}
