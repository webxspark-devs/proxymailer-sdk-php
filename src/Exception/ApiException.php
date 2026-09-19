<?php

declare(strict_types=1);

namespace ProxyMailer\Exception;

/** API or transport failure (non-auth / non-validation / non-rate-limit). */
final class ApiException extends ProxyMailerException {}
