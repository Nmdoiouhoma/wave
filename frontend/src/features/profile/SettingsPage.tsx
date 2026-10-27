import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router';
import { useLogout } from '@/api/queries/auth';
import {
  useChangePassword,
  useCurrentUser,
  useDeleteMyAvatar,
  useMe,
  useUpdateMe,
  useUploadMyAvatar,
} from '@/api/queries/me';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextArea, TextField } from '@/components/ui/Field';
import { LogoutIcon } from '@/components/ui/icons';
import { ImageUploader } from '@/components/ui/ImageUploader';
import { Avatar } from '@/components/ui/Media';
import { formErrors } from '@/features/auth/formErrors';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="bg-surface ring-line rounded-2xl p-5 ring-1" aria-label={title}>
      <h2 className="mb-4 font-semibold">{title}</h2>
      {children}
    </section>
  );
}

export function SettingsPage() {
  const me = useCurrentUser();
  const meQuery = useMe();
  const avatarGate = useVerifiedGate('changer ton avatar');
  const uploadAvatar = useUploadMyAvatar();
  const deleteAvatar = useDeleteMyAvatar();
  const logout = useLogout();
  const navigate = useNavigate();

  return (
    <div className="mx-auto flex max-w-xl flex-col gap-6">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Paramètres</h1>
        <p className="text-muted mt-1 text-sm">
          @{me.username} · {me.email}
        </p>
      </div>

      <Section title="Avatar">
        <ImageUploader
          currentUrl={me.avatarUrl}
          preview={
            <Avatar
              url={me.avatarUrl}
              name={me.displayName}
              seed={me.id}
              className="size-20 text-2xl"
            />
          }
          upload={(f) => uploadAvatar.mutateAsync(f)}
          remove={() => deleteAvatar.mutateAsync()}
          refetch={meQuery.refetch}
          disabledReason={avatarGate}
          label="Changer l’avatar"
        />
      </Section>

      <Section title="Profil">
        <ProfileForm key={me.id} />
      </Section>

      <Section title="Mot de passe">
        <PasswordForm />
      </Section>

      <Button
        variant="secondary"
        icon={<LogoutIcon size={16} />}
        loading={logout.isPending}
        onClick={() =>
          logout.mutate(undefined, { onSettled: () => void navigate('/login', { replace: true }) })
        }
      >
        Se déconnecter
      </Button>
    </div>
  );
}

function ProfileForm() {
  const me = useCurrentUser();
  const [displayName, setDisplayName] = useState(me.displayName);
  const [bio, setBio] = useState(me.bio ?? '');
  const update = useUpdateMe();
  const { fields, message } = formErrors(update.error, ['displayName', 'bio']);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    update.mutate({ displayName: displayName.trim(), bio: bio.trim() || null });
  };

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      {update.isSuccess && <Alert tone="success">Profil mis à jour.</Alert>}
      <TextField
        label="Nom affiché"
        value={displayName}
        maxLength={50}
        onChange={(e) => setDisplayName(e.target.value)}
        error={fields.displayName}
      />
      <TextArea
        label="Bio"
        value={bio}
        maxLength={300}
        onChange={(e) => setBio(e.target.value)}
        error={fields.bio}
        hint={`${bio.length}/300`}
      />
      <p className="text-faint text-xs">
        Le nom d’utilisateur et l’adresse e-mail ne sont pas modifiables.
      </p>
      <div>
        <Button type="submit" loading={update.isPending} disabled={!displayName.trim()}>
          Enregistrer
        </Button>
      </div>
    </form>
  );
}

function PasswordForm() {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [local, setLocal] = useState<string | null>(null);
  const change = useChangePassword();
  const { fields, message } = formErrors(change.error, ['currentPassword', 'newPassword']);
  const [done, setDone] = useState(false);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (next.length < 10) {
      setLocal('Au moins 10 caractères.');
      return;
    }
    setLocal(null);
    setDone(false);
    change.mutate(
      { currentPassword: current, newPassword: next },
      {
        onSuccess: () => {
          setDone(true);
          setCurrent('');
          setNext('');
        },
      },
    );
  };

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      {done && (
        <Alert tone="success">
          Mot de passe modifié. Tes autres appareils devront se reconnecter.
        </Alert>
      )}
      <TextField
        label="Mot de passe actuel"
        type="password"
        autoComplete="current-password"
        value={current}
        onChange={(e) => setCurrent(e.target.value)}
        error={fields.currentPassword}
      />
      <TextField
        label="Nouveau mot de passe"
        type="password"
        autoComplete="new-password"
        value={next}
        onChange={(e) => setNext(e.target.value)}
        error={local ?? fields.newPassword}
        hint="10 caractères minimum."
      />
      <div>
        <Button type="submit" loading={change.isPending} disabled={!current || !next}>
          Changer le mot de passe
        </Button>
      </div>
    </form>
  );
}
