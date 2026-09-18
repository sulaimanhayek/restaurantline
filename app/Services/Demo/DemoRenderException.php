<?php

declare(strict_types=1);

namespace App\Services\Demo;

use RuntimeException;

/**
 * Something went wrong turning a transcript into something you can play.
 *
 * Separate from `ElevenLabsException` because the two fail for different
 * reasons and want different advice: that one means the provider said no, this
 * one usually means the audio coming back is not the shape the stitcher can
 * join, and the fix is a config value rather than an account.
 */
final class DemoRenderException extends RuntimeException {}
