// Сборку делает `@tailwindcss/cli` (см. package.json), он встраивает tailwindcss и autoprefixer
// сам. Этот файл — заглушка на случай вызова postcss вне CLI: отдельными плагинами v4 не требует.
export default {
  plugins: {},
};
