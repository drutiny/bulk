<?php

namespace Drutiny\Bulk\Message;

enum MessageStatus: int {
    case SUCCESS = 0;
    case RETRY = 1;
    case FAIL = 2;
    case NONE = -1;
}