<?php

namespace App\Enums;

enum TicketMessageType: string
{
    case Customer = 'customer';
    case AgentReply = 'agent_reply';
    case InternalNote = 'internal_note';
}
