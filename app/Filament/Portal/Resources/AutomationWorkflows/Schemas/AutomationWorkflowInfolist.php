<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows\Schemas;

use App\Filament\Admin\Resources\Automation\AutomationWorkflows\Schemas\AutomationWorkflowInfolist as AdminAutomationWorkflowInfolist;
use Filament\Schemas\Schema;

class AutomationWorkflowInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return AdminAutomationWorkflowInfolist::configure($schema);
    }
}
