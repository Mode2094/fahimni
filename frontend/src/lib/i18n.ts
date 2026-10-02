export type Locale = 'en' | 'ar';

const dict: Record<string, Record<string, string>> = {
  en: {
    'common.email': 'Email',
    'common.password': 'Password',
    'common.login': 'Login',
    'common.register': 'Register',
    'common.logout': 'Logout',
    'common.forgotPassword': 'Forgot password?',
    'common.invalidCredentials': 'Invalid email or password',
    'common.dontHaveAccount': "Don't have an account?",
    'common.createOne': 'Create one',
    'common.welcomeBack': 'Welcome back',
    'common.signInToAccount': 'Sign in to your account',
    'common.signIn': 'Sign In',
    'common.signingIn': 'Signing in...',
    'common.save': 'Save',
    'common.cancel': 'Cancel',
    'common.delete': 'Delete',
    'common.edit': 'Edit',
    'common.back': 'Back',
  },
  ar: {
    'common.email': 'البريد الإلكتروني',
    'common.password': 'كلمة المرور',
    'common.login': 'تسجيل دخول',
    'common.register': 'إنشاء حساب',
    'common.logout': 'تسجيل خروج',
    'common.forgotPassword': 'نسيت كلمة المرور؟',
    'common.invalidCredentials': 'البريد الإلكتروني أو كلمة المرور غير صحيحة',
    'common.dontHaveAccount': 'ليس لديك حساب؟',
    'common.createOne': 'إنشاء واحد',
    'common.welcomeBack': 'مرحباً بعودتك',
    'common.signInToAccount': 'سجل الدخول إلى حسابك',
    'common.signIn': 'تسجيل دخول',
    'common.signingIn': 'جاري تسجيل الدخول...',
    'common.save': 'حفظ',
    'common.cancel': 'إلغاء',
    'common.delete': 'حذف',
    'common.edit': 'تعديل',
    'common.back': 'رجوع',
  },
};

export function t(locale: Locale, path: string, fallback?: string): string {
  return dict[locale]?.[path] || dict.en[path] || fallback || path;
}
