<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class OrganizationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.portal.pages.organization-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $organization = Filament::getTenant();
        $user = auth()->user();

        return $organization instanceof Organization
            && $user instanceof User
            && $user->can('updateSettings', $organization);
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Organization';
    }

    public static function getNavigationLabel(): string
    {
        return 'Settings';
    }

    public function getSubheading(): ?string
    {
        return 'Manage the identity shown to members of this organization.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $organization = $this->organization();

        $this->form->fill([
            'name' => $organization->name,
            'slug' => $organization->slug,
            'logo' => $organization->logo,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Organization identity')
                    ->description('These details are shared across dashboards, reports, and member navigation.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label('Portal identifier')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('Contact a platform administrator to change this identifier.'),
                        FileUpload::make('logo')
                            ->image()
                            ->disk('public')
                            ->directory('organization-logos')
                            ->visibility('public')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $data = $this->form->getState();
        $organization = $this->organization();
        $organization->update([
            'name' => $data['name'],
            'logo' => $data['logo'] ?? null,
        ]);

        Notification::make()
            ->success()
            ->title('Organization settings saved')
            ->send();
    }

    private function organization(): Organization
    {
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }
}
