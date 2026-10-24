import { isApiError, type ErrorCode } from '@/api/errors';
import { errorMessage } from '@/lib/errorMessages';

export interface FormErrors {
  fields: Record<string, string>;
  message: string | null;
}

/**
 * Maps an API error onto form fields: `violations` by `propertyPath`, plus business codes
 * attached to a field (e.g. `email_taken` → `email`). Anything else becomes a form message.
 */
export function formErrors(
  error: unknown,
  knownFields: readonly string[],
  codeToField: Partial<Record<ErrorCode, string>> = {},
): FormErrors {
  if (!error) return { fields: {}, message: null };
  if (!isApiError(error)) return { fields: {}, message: errorMessage(error) };
  const mapped = codeToField[error.code];
  if (mapped) return { fields: { [mapped]: errorMessage(error) }, message: null };
  if (error.code === 'validation_failed' && error.violations.length) {
    const fields: Record<string, string> = {};
    const unmapped: string[] = [];
    for (const v of error.violations) {
      if (knownFields.includes(v.propertyPath)) fields[v.propertyPath] ??= v.message;
      else unmapped.push(v.message);
    }
    return { fields, message: unmapped.length ? unmapped.join(' ') : null };
  }
  return { fields: {}, message: errorMessage(error) };
}
