<div>
    {{-- Page header --}}
    <div class="mb-6">
        <h1 class="text-xl font-bold text-slate-900">User Management</h1>
        <p class="text-sm text-slate-500 mt-0.5">Account governance, role assignment, and session control.</p>
    </div>

    {{-- Step-up modal --}}
    @if ($showStepUp)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
                <h2 class="text-base font-semibold text-slate-800 mb-1">Confirm your identity</h2>
                <p class="text-sm text-slate-500 mb-4">Enter your current password to authorise this action.</p>
                @if ($stepUpError)
                    <p class="text-sm text-red-600 mb-3">{{ $stepUpError }}</p>
                @endif
                <input type="password" wire:model="stepUpPassword"
                       wire:keydown.enter="verifyStepUp"
                       placeholder="Current password"
                       class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 mb-4"
                       autofocus />
                <div class="flex gap-2 justify-end">
                    <button wire:click="cancelStepUp"
                            class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">Cancel</button>
                    <button wire:click="verifyStepUp"
                            class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Delete confirmation modal --}}
    @if ($showDeleteConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
                <h2 class="text-base font-semibold text-slate-800 mb-1">Delete account?</h2>
                <p class="text-sm text-slate-600 mb-4">
                    This will permanently remove
                    <span class="font-mono font-semibold text-slate-800">{{ $deleteConfirmName }}</span>
                    and revoke all active sessions.
                    This action cannot be undone.
                </p>
                <div class="flex gap-2 justify-end">
                    <button wire:click="closeDeleteConfirm"
                            class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">Cancel</button>
                    <button wire:click="confirmDelete"
                            class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium hover:bg-red-700">
                        Delete account
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Role form modal --}}
    @if ($showRoleForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div class="bg-white rounded-xl shadow-xl p-6 w-full max-w-sm">
                <h2 class="text-base font-semibold text-slate-800 mb-4">
                    {{ $roleFormAction === 'assign' ? 'Assign Role' : 'Revoke Role' }}
                </h2>
                <label class="block text-sm font-medium text-slate-700 mb-1">Role</label>
                <select wire:model="roleFormRole"
                        class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300 mb-4">
                    <option value="">Select a role…</option>
                    @foreach ($allRoles as $r)
                        <option value="{{ $r }}">{{ $r }}</option>
                    @endforeach
                </select>
                <div class="flex gap-2 justify-end">
                    <button wire:click="closeRoleForm"
                            class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">Cancel</button>
                    <button wire:click="submitRoleForm"
                            class="px-4 py-2 rounded-lg {{ $roleFormAction === 'assign' ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-red-600 hover:bg-red-700' }} text-white text-sm font-medium">
                        {{ ucfirst($roleFormAction) }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Flash --}}
    @if ($flashMessage)
        <div class="mb-4 px-4 py-3 rounded-lg text-sm font-medium
            {{ $flashType === 'success'
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                : 'bg-red-50 text-red-700 border border-red-200' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Step-up status --}}
    <div class="mb-4">
        @if ($stepUpOk)
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-medium">
                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
                Identity verified (15-min window)
            </span>
        @else
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 border border-slate-200 text-xs">
                Password required for write actions
            </span>
        @endif
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-5">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Search username or display name…"
               class="w-full sm:w-64 px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
        <select wire:model.live="statusFilter"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="locked">Locked</option>
            <option value="suspended">Suspended</option>
            <option value="frozen">Frozen</option>
        </select>
        <select wire:model.live="roleFilter"
                class="px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">All roles</option>
            @foreach ($allRoles as $r)
                <option value="{{ $r }}">{{ $r }}</option>
            @endforeach
        </select>
    </div>

    {{-- User table --}}
    @if ($users->isEmpty())
        <div class="text-center py-16 bg-white rounded-xl border border-slate-200">
            <p class="text-slate-500 font-medium">No users match the current filters.</p>
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50">
                        <th class="text-left px-4 py-3 font-semibold text-slate-600">User</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden sm:table-cell">Status</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden md:table-cell">Roles</th>
                        <th class="text-left px-4 py-3 font-semibold text-slate-600 hidden lg:table-cell">Audience</th>
                        <th class="px-4 py-3 text-right font-semibold text-slate-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($users as $user)
                        @php
                            $statusClass = match($user->status) {
                                'active'    => 'bg-emerald-100 text-emerald-700',
                                'locked'    => 'bg-yellow-100 text-yellow-700',
                                'suspended' => 'bg-red-100 text-red-700',
                                'frozen'    => 'bg-blue-100 text-blue-700',
                                default     => 'bg-slate-100 text-slate-600',
                            };
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3">
                                <button wire:click="openDetail({{ $user->id }})"
                                        class="text-left hover:text-indigo-600 transition-colors">
                                    <span class="font-medium text-slate-800">{{ $user->username }}</span>
                                    <span class="block text-xs text-slate-400">{{ $user->display_name }}</span>
                                </button>
                                @if ($user->must_change_password)
                                    <span class="inline-flex items-center mt-0.5 px-1.5 py-0.5 rounded text-xs bg-orange-50 text-orange-600 border border-orange-200">
                                        must reset password
                                    </span>
                                @endif
                                @if ($user->deleted_at)
                                    <span class="inline-flex items-center mt-0.5 px-1.5 py-0.5 rounded text-xs bg-slate-100 text-slate-500">
                                        deleted
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 hidden sm:table-cell">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                                    {{ ucfirst($user->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 hidden md:table-cell">
                                {{ $user->roles->pluck('name')->join(', ') ?: '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 hidden lg:table-cell">
                                {{ $user->audience_type ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-1 justify-end flex-wrap">
                                    @if ($user->status === 'active' || $user->status === 'frozen')
                                        <button wire:click="lockUser({{ $user->id }})"
                                                class="px-2 py-1 text-xs rounded bg-yellow-50 text-yellow-700 hover:bg-yellow-100 border border-yellow-200">
                                            Lock
                                        </button>
                                        <button wire:click="suspendUser({{ $user->id }})"
                                                class="px-2 py-1 text-xs rounded bg-red-50 text-red-700 hover:bg-red-100 border border-red-200">
                                            Suspend
                                        </button>
                                    @endif
                                    @if ($user->status === 'locked')
                                        <button wire:click="unlockUser({{ $user->id }})"
                                                class="px-2 py-1 text-xs rounded bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200">
                                            Unlock
                                        </button>
                                    @endif
                                    @if ($user->status === 'suspended')
                                        <button wire:click="reactivateUser({{ $user->id }})"
                                                class="px-2 py-1 text-xs rounded bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200">
                                            Reactivate
                                        </button>
                                    @endif
                                    <button wire:click="forcePasswordReset({{ $user->id }})"
                                            class="px-2 py-1 text-xs rounded bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200">
                                        Reset pwd
                                    </button>
                                    <button wire:click="revokeSessions({{ $user->id }})"
                                            class="px-2 py-1 text-xs rounded bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200">
                                        Revoke sessions
                                    </button>
                                    <button wire:click="openRoleForm({{ $user->id }}, 'assign')"
                                            class="px-2 py-1 text-xs rounded bg-indigo-50 text-indigo-600 hover:bg-indigo-100 border border-indigo-200">
                                        +Role
                                    </button>
                                    <button wire:click="openRoleForm({{ $user->id }}, 'revoke')"
                                            class="px-2 py-1 text-xs rounded bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200">
                                        −Role
                                    </button>
                                    @unless ($user->deleted_at)
                                        <button wire:click="openDeleteConfirm({{ $user->id }})"
                                                class="px-2 py-1 text-xs rounded bg-red-50 text-red-700 hover:bg-red-100 border border-red-200">
                                            Delete
                                        </button>
                                    @endunless
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5">{{ $users->links() }}</div>
    @endif

    {{-- Detail panel --}}
    @if ($detailUser)
        <div class="fixed inset-0 z-40 flex items-start justify-end bg-black/20"
             wire:click.self="closeDetail">
            <div class="w-full max-w-md h-full bg-white shadow-2xl overflow-y-auto p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-base font-semibold text-slate-800">{{ $detailUser->username }}</h2>
                    <button wire:click="closeDetail" class="text-slate-400 hover:text-slate-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Display name</dt>
                        <dd class="font-medium">{{ $detailUser->display_name }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Status</dt>
                        <dd>{{ ucfirst($detailUser->status) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Audience type</dt>
                        <dd>{{ $detailUser->audience_type ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Roles</dt>
                        <dd>{{ $detailUser->roles->pluck('name')->join(', ') ?: 'none' }}</dd>
                    </div>
                    @if ($detailUser->locked_until)
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Locked until</dt>
                            <dd class="text-yellow-700">{{ $detailUser->locked_until->format('Y-m-d H:i') }}</dd>
                        </div>
                    @endif
                    @if ($detailUser->booking_freeze_until && $detailUser->booking_freeze_until->isFuture())
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Booking freeze</dt>
                            <dd class="text-blue-700">until {{ $detailUser->booking_freeze_until->format('Y-m-d') }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Must reset password</dt>
                        <dd>{{ $detailUser->must_change_password ? 'Yes' : 'No' }}</dd>
                    </div>
                    @if ($detailUser->profile)
                        <hr class="border-slate-100 my-2">
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Employee ID</dt>
                            <dd class="font-mono">{{ $detailUser->profile->employee_id ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Job title</dt>
                            <dd>{{ $detailUser->profile->job_title ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-slate-500">Employment status</dt>
                            <dd>{{ $detailUser->profile->employment_status ?? '—' }}</dd>
                        </div>
                    @endif
                    @if ($detailUser->appSessions->isNotEmpty())
                        <hr class="border-slate-100 my-2">
                        <p class="text-xs font-semibold text-slate-600 mb-1">Recent active sessions</p>
                        @foreach ($detailUser->appSessions as $session)
                            <div class="text-xs text-slate-500">
                                last active {{ $session->last_active_at?->diffForHumans() ?? 'unknown' }}
                            </div>
                        @endforeach
                    @endif
                </dl>
            </div>
        </div>
    @endif
</div>
