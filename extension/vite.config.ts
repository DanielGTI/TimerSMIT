import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  // Essencial para extensões do Azure DevOps: o Marketplace serve o
  // index.html a partir de um caminho aninhado no CDN, não da raiz do
  // domínio. Com base absoluto (padrão do Vite), as tags <script>/<link>
  // apontam para "/assets/..." e 404 nesse CDN; relativo resolve certo
  // não importa onde o arquivo seja servido.
  base: "./",
  plugins: [react()],
  build: {
    outDir: "dist",
    emptyOutDir: true,
    rollupOptions: {
      input: {
        "work-item": "src/pages/work-item/index.html",
        timesheet: "src/pages/timesheet/index.html",
        approvals: "src/pages/approvals/index.html",
        reports: "src/pages/reports/index.html",
        settings: "src/pages/settings/index.html",
      },
    },
  },
  test: {
    environment: "jsdom",
    setupFiles: ["tests/setup.ts"],
  },
});
