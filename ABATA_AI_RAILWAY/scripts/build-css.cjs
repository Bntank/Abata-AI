const fs=require('node:fs');const path=require('node:path');
const {createRequire}=require('node:module');const req=createRequire(path.join(process.cwd(),'package.json'));
const resolved=process.env.TAILWIND_MODULE||req.resolve('tailwindcss');const tailwind=require(resolved);
const root=path.resolve(path.dirname(resolved),'..');
function scan(dir){return fs.readdirSync(dir,{withFileTypes:true}).flatMap(e=>e.isDirectory()?scan(path.join(dir,e.name)):[path.join(dir,e.name)]);}
(async()=>{const theme=fs.readFileSync(path.join(root,'theme.css'),'utf8');const compiler=await tailwind.compile('@layer theme, utilities;\n@layer theme {\n'+theme+'\n}\n@layer utilities { @tailwind utilities; }');const tokens=new Set();for(const p of scan('resources/views')){const s=fs.readFileSync(p,'utf8');for(const m of s.matchAll(/class="([^"\n]*)"/g))for(const c of m[1].split(/\s+/))tokens.add(c);}const css=compiler.build([...tokens]);fs.writeFileSync('public/app.css',css+'\n'+fs.readFileSync('resources/css/app.css','utf8'));console.log('Tailwind CSS + component styles built.');})().catch(e=>{console.error(e);process.exit(1)});
