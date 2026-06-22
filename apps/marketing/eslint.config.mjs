/** @type {import("eslint").Linter.Config[]} */
export default [
  {
    ignores: [
      ".next/**",
      "node_modules/**",
      "scripts/**",
    ],
  },
  {
    rules: {
      "no-undef": "off",
      "no-unused-vars": "off",
    },
  },
];
