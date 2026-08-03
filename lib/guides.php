<?php
declare(strict_types=1);

function dashboard_guide_sections(string $path): array {
  if (!is_readable($path)) {
    return [];
  }

  $sections = [];
  foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*\d+\.\s+([a-z0-9-]+)\.md\s*$/', $line, $matches)) {
      $sections[] = $matches[1];
    }
  }

  return array_values(array_unique($sections));
}

function dashboard_markdown_title(string $markdown, string $fallback): string {
  foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
    if (preg_match('/^#\s+(.+)$/', trim($line), $matches)) {
      return trim($matches[1]);
    }
  }

  return $fallback;
}
