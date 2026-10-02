import { init, cn } from '@/lib/utils';

type AvatarProps = {
  name?: string | null;
  src?: string | null;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
};

export function Avatar({ name, src, size = 'md', className }: AvatarProps) {
  const sizes = { sm: 'h-8 w-8 text-[10px]', md: 'h-10 w-10 text-xs', lg: 'h-14 w-14 text-base' };
  if (src) {
    return <img src={src} alt={name || ''} className={cn('shrink-0 rounded-full object-cover', sizes[size], className)} />;
  }
  return (
    <span
      className={cn('flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-teal-600 to-teal-500 font-bold text-white', sizes[size], className)}
    >
      {name ? init(name) : '?'}
    </span>
  );
}
