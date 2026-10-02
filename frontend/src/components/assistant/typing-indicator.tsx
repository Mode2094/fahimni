export default function TypingIndicator({ text = '' }: { text?: string }) {
  return (
    <div className="inline-flex items-center gap-2 max-w-[85%] rounded-2xl rounded-es-md bg-white border border-slate-100 shadow-sm px-4 py-3">
      <span className="flex items-center gap-1">
        <span className="typing-dot h-1.5 w-1.5 rounded-full bg-violet-500" style={{ animationDelay: '0ms' }} />
        <span className="typing-dot h-1.5 w-1.5 rounded-full bg-violet-500" style={{ animationDelay: '150ms' }} />
        <span className="typing-dot h-1.5 w-1.5 rounded-full bg-violet-500" style={{ animationDelay: '300ms' }} />
      </span>
      {text && <span className="text-xs text-slate-500">{text}</span>}
    </div>
  );
}
