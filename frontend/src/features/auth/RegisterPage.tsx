import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router';
import { useRegister } from '@/api/queries/auth';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { AuthLayout } from './AuthLayout';
import { formErrors } from './formErrors';

const FIELDS = ['email', 'username', 'displayName', 'password'] as const;
type FieldName = (typeof FIELDS)[number];

function validate(v: Record<FieldName, string>): Partial<Record<FieldName, string>> {
  const out: Partial<Record<FieldName, string>> = {};
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v.email.trim())) out.email = 'Adresse e-mail invalide.';
  if (!/^[a-z0-9_.]{3,30}$/.test(v.username))
    out.username = '3 à 30 caractères : minuscules, chiffres, « _ » ou « . ».';
  if (!v.displayName.trim()) out.displayName = 'Indiquez le nom affiché sur votre profil.';
  else if (v.displayName.length > 50) out.displayName = '50 caractères maximum.';
  if (v.password.length < 10) out.password = 'Au moins 10 caractères.';
  else if (v.password.length > 128) out.password = '128 caractères maximum.';
  return out;
}

export function RegisterPage() {
  const [values, setValues] = useState<Record<FieldName, string>>({
    email: '',
    username: '',
    displayName: '',
    password: '',
  });
  const [local, setLocal] = useState<Partial<Record<FieldName, string>>>({});
  const register = useRegister();
  const navigate = useNavigate();
  const server = formErrors(register.error, FIELDS, {
    email_taken: 'email',
    username_taken: 'username',
  });
  const errorOf = (f: FieldName) => local[f] ?? server.fields[f];

  const set = (f: FieldName) => (e: { target: { value: string } }) => {
    const value =
      f === 'username' ? e.target.value.toLowerCase().replace(/\s/g, '') : e.target.value;
    setValues((v) => ({ ...v, [f]: value }));
    setLocal((l) => ({ ...l, [f]: undefined }));
  };

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    const errs = validate(values);
    setLocal(errs);
    if (Object.keys(errs).length) return;
    register.mutate(
      { ...values, email: values.email.trim(), displayName: values.displayName.trim() },
      { onSuccess: () => void navigate('/', { replace: true }) },
    );
  };

  return (
    <AuthLayout
      title="Créer un compte"
      subtitle="Importez vos titres, suivez vos artistes préférés."
      footer={
        <>
          Déjà inscrit·e ?{' '}
          <Link to="/login" className="text-fg font-semibold hover:underline">
            Se connecter
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        {server.message && <Alert tone="error">{server.message}</Alert>}
        <TextField
          label="Adresse e-mail"
          type="email"
          autoComplete="email"
          inputMode="email"
          value={values.email}
          onChange={set('email')}
          error={errorOf('email')}
        />
        <TextField
          label="Nom d’utilisateur"
          autoComplete="username"
          autoCapitalize="none"
          spellCheck={false}
          value={values.username}
          onChange={set('username')}
          error={errorOf('username')}
          hint={
            values.username
              ? `Votre profil : /u/${values.username}`
              : 'Minuscules, chiffres, « _ » et « . ».'
          }
        />
        <TextField
          label="Nom affiché"
          autoComplete="name"
          value={values.displayName}
          onChange={set('displayName')}
          error={errorOf('displayName')}
        />
        <TextField
          label="Mot de passe"
          type="password"
          autoComplete="new-password"
          value={values.password}
          onChange={set('password')}
          error={errorOf('password')}
          hint="10 caractères minimum."
        />
        <Button type="submit" size="lg" loading={register.isPending}>
          Créer mon compte
        </Button>
      </form>
    </AuthLayout>
  );
}
