<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accept invitation</title>
    <style>
        :root { color-scheme: light dark; font-family: ui-sans-serif, system-ui, sans-serif; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f3f4f6; color: #111827; }
        main { width: min(28rem, calc(100% - 2rem)); background: white; border-radius: 1rem; padding: 2rem; box-shadow: 0 20px 45px rgb(15 23 42 / 0.12); }
        h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
        p { color: #4b5563; line-height: 1.5; }
        label { display: grid; gap: .4rem; margin-top: 1rem; font-weight: 600; }
        input { border: 1px solid #d1d5db; border-radius: .6rem; padding: .75rem; font: inherit; background: white; color: #111827; }
        button { width: 100%; border: 0; border-radius: .6rem; margin-top: 1.25rem; padding: .8rem; background: #2563eb; color: white; font: inherit; font-weight: 700; cursor: pointer; }
        .error { color: #b91c1c; font-size: .875rem; margin-top: .35rem; }
        .role { display: inline-block; border-radius: 999px; background: #dbeafe; color: #1e40af; padding: .2rem .65rem; font-weight: 700; }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e5e7eb; }
            main { background: #111827; }
            p { color: #cbd5e1; }
            input { background: #1f2937; color: #f9fafb; border-color: #475569; }
        }
    </style>
</head>
<body>
<main>
    <h1>Join {{ $invitation->organization->name }}</h1>
    <p>
        You were invited as
        <span class="role">{{ $invitation->tenant_role_key->label() }}</span>.
    </p>
    <p>This invitation was sent to {{ $invitation->email }}.</p>

    <form method="POST" action="{{ route('portal.invitations.store', ['invitation' => $invitation, 'token' => $token]) }}">
        @csrf

        @unless ($existingUser)
            <label>
                Name
                <input name="name" value="{{ old('name') }}" autocomplete="name" required>
            </label>
            @error('name') <div class="error">{{ $message }}</div> @enderror

            <label>
                Password
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            @error('password') <div class="error">{{ $message }}</div> @enderror

            <label>
                Confirm password
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
            </label>
        @endunless

        @error('invitation') <div class="error">{{ $message }}</div> @enderror

        <button type="submit">Accept invitation</button>
    </form>
</main>
</body>
</html>
