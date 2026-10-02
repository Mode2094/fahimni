'use client';
import { createContext, useContext, isValidElement, cloneElement, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

const DialogContext = createContext<{ onOpenChange: (v: boolean) => void }>({ onOpenChange: () => {} });

export function Dialog({ open, onOpenChange, children, className }: { open: boolean; onOpenChange: (v: boolean) => void; children: React.ReactNode; className?: string }) {
  return (
    <DialogContext.Provider value={{ onOpenChange }}>
      {open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
          <div className="absolute inset-0 bg-black/50" onClick={() => onOpenChange(false)} />
          <div className={cn('relative z-10 w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-card p-6 shadow-2xl animate-in', className)}>{children}</div>
        </div>
      )}
    </DialogContext.Provider>
  );
}
export function DialogTrigger({ asChild, children, ...props }: { asChild?: boolean; children: ReactNode } & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const { onOpenChange } = useContext(DialogContext);
  const handleClick = (e: React.MouseEvent<HTMLButtonElement>) => {
    props.onClick?.(e as any);
    onOpenChange(true);
  };
  if (asChild && isValidElement(children)) {
    return cloneElement(children as React.ReactElement<any>, { onClick: handleClick });
  }
  return <button onClick={handleClick}>{children}</button>;
}
export function DialogContent({ children, className }: { children: React.ReactNode; className?: string }) { return <div className={className}>{children}</div>; }
export function DialogHeader({ children, className }: { children: React.ReactNode; className?: string }) { return <div className={cn('mb-4', className)}>{children}</div>; }
export function DialogTitle({ children, className }: { children: React.ReactNode; className?: string }) { return <h2 className={cn('text-lg font-bold', className)}>{children}</h2>; }
export function DialogFooter({ children, className }: { children: React.ReactNode; className?: string }) {
  return <div className={cn('mt-6 flex justify-end gap-2', className)}>{children}</div>;
}
