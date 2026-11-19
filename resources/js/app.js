import Alpine from 'alpinejs';
import { registerCharts } from './charts';

registerCharts(Alpine);

window.Alpine = Alpine;
Alpine.start();
