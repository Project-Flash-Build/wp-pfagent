import { Fragment, ReactNode } from 'react';

/**
 * Tiny safe markdown renderer for assistant messages. Handles paragraphs,
 * line breaks, **bold**, *italic*, `inline code`, fenced code blocks,
 * unordered (`-`, `*`) and ordered (`1.`) lists. No raw HTML is ever
 * injected — every node is constructed as a React element, so the LLM
 * cannot smuggle <script> in.
 */
export function Markdown({ text }: { text: string }) {
  if (!text) {
    return null;
  }

  const blocks = parseBlocks(text);
  return (
    <>
      {blocks.map((block, index) => (
        <Fragment key={index}>{renderBlock(block, index)}</Fragment>
      ))}
    </>
  );
}

type Block =
  | { type: 'paragraph'; lines: string[] }
  | { type: 'code'; language: string; content: string }
  | { type: 'list'; ordered: boolean; items: string[] };

function parseBlocks(text: string): Block[] {
  const lines = text.replace(/\r\n/g, '\n').split('\n');
  const blocks: Block[] = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    if (line.startsWith('```')) {
      const language = line.slice(3).trim();
      const content: string[] = [];
      i += 1;
      while (i < lines.length && !lines[i].startsWith('```')) {
        content.push(lines[i]);
        i += 1;
      }
      i += 1;
      blocks.push({ type: 'code', language, content: content.join('\n') });
      continue;
    }

    if (/^\s*[-*]\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^\s*[-*]\s+/, ''));
        i += 1;
      }
      blocks.push({ type: 'list', ordered: false, items });
      continue;
    }

    if (/^\s*\d+\.\s+/.test(line)) {
      const items: string[] = [];
      while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^\s*\d+\.\s+/, ''));
        i += 1;
      }
      blocks.push({ type: 'list', ordered: true, items });
      continue;
    }

    if (line.trim() === '') {
      i += 1;
      continue;
    }

    const paragraph: string[] = [];
    while (i < lines.length && lines[i].trim() !== '' && !lines[i].startsWith('```') && !/^\s*[-*]\s+/.test(lines[i]) && !/^\s*\d+\.\s+/.test(lines[i])) {
      paragraph.push(lines[i]);
      i += 1;
    }
    blocks.push({ type: 'paragraph', lines: paragraph });
  }

  return blocks;
}

function renderBlock(block: Block, key: number): ReactNode {
  if (block.type === 'code') {
    return (
      <pre className="pfa-md-code" key={key}>
        <code>{block.content}</code>
      </pre>
    );
  }

  if (block.type === 'list') {
    const ListTag = block.ordered ? 'ol' : 'ul';
    return (
      <ListTag className="pfa-md-list" key={key}>
        {block.items.map((item, itemIndex) => (
          <li key={itemIndex}>{renderInline(item)}</li>
        ))}
      </ListTag>
    );
  }

  return (
    <p className="pfa-md-paragraph" key={key}>
      {block.lines.map((line, lineIndex) => (
        <Fragment key={lineIndex}>
          {lineIndex > 0 ? <br /> : null}
          {renderInline(line)}
        </Fragment>
      ))}
    </p>
  );
}

function renderInline(text: string): ReactNode {
  // Tokenize on inline code first (single backticks) — its content is
  // verbatim and never re-parsed.
  const tokens: ReactNode[] = [];
  const codeRegex = /`([^`]+)`/g;
  let lastIndex = 0;
  let key = 0;
  let match: RegExpExecArray | null;

  while ((match = codeRegex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      tokens.push(...renderEmphasis(text.slice(lastIndex, match.index), key));
      key += 1;
    }
    tokens.push(<code key={`code-${key}`}>{match[1]}</code>);
    key += 1;
    lastIndex = match.index + match[0].length;
  }
  if (lastIndex < text.length) {
    tokens.push(...renderEmphasis(text.slice(lastIndex), key));
  }

  return tokens;
}

function renderEmphasis(text: string, baseKey: number): ReactNode[] {
  // **bold** then *italic*. Process bold first to avoid overlap.
  const result: ReactNode[] = [];
  const boldRegex = /\*\*([^*]+)\*\*/g;
  let lastIndex = 0;
  let match: RegExpExecArray | null;
  let key = 0;

  while ((match = boldRegex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      result.push(...renderItalic(text.slice(lastIndex, match.index), `${baseKey}-${key}`));
      key += 1;
    }
    result.push(<strong key={`bold-${baseKey}-${key}`}>{match[1]}</strong>);
    key += 1;
    lastIndex = match.index + match[0].length;
  }
  if (lastIndex < text.length) {
    result.push(...renderItalic(text.slice(lastIndex), `${baseKey}-${key}`));
  }

  return result;
}

function renderItalic(text: string, baseKey: string): ReactNode[] {
  const result: ReactNode[] = [];
  const italicRegex = /\*([^*\s][^*]*[^*\s]|[^*\s])\*/g;
  let lastIndex = 0;
  let match: RegExpExecArray | null;
  let key = 0;

  while ((match = italicRegex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      result.push(<Fragment key={`text-${baseKey}-${key}`}>{text.slice(lastIndex, match.index)}</Fragment>);
      key += 1;
    }
    result.push(<em key={`em-${baseKey}-${key}`}>{match[1]}</em>);
    key += 1;
    lastIndex = match.index + match[0].length;
  }
  if (lastIndex < text.length) {
    result.push(<Fragment key={`text-${baseKey}-${key}`}>{text.slice(lastIndex)}</Fragment>);
  }

  return result;
}
