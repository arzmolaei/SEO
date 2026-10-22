<?php

namespace App\Domain\Keywords;

enum ClusterLifecycleState: string
{
    case Planning = 'planning';
    case Ready = 'ready';
}
