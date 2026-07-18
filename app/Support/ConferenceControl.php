<?php

namespace App\Support;

use App\Exceptions\ConferenceControlUnavailableException;
use RuntimeException;
use Throwable;

class ConferenceControl
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function rescue(callable $callback)
    {
        try {
            return $callback();
        } catch (ConferenceControlUnavailableException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'FreeSWITCH event socket')) {
                throw $exception;
            }

            throw ConferenceControlUnavailableException::freeswitchUnavailable($exception->getMessage());
        } catch (Throwable $throwable) {
            throw $throwable;
        }
    }
}
