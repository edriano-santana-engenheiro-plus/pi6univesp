<?php
declare(strict_types=1);

use Delivery\Utils\Auth;
use Delivery\Utils\View;

Auth::logout();
View::redirect('/login', 'Sessão encerrada.');
