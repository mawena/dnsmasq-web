<?php
declare(strict_types=1);
logout_user();
flash_set('ok', 'Vous êtes déconnecté.');
redirect('/login');
